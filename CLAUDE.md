# CLAUDE.md — Travel Manager

Guidance for Claude Code (and humans) working in this repository.

## 1. What this app is

A self-hosted **Nextcloud 33/34 app** that automatically parses travel-booking
emails from a per-user dedicated mailbox, extracts structured booking data with
an LLM, and surfaces it in the app UI (later: Calendar/Notes projection).

- **Multi-user**, with hard per-user data isolation (every query partitioned by `user_id`).
- **One app owns the whole pipeline**: ingestion, classification, extraction,
  canonical data model, and future projection. Do **not** split into separate apps.
- The LLM stays **outside** the app behind an abstraction; the app holds no API keys.

MVP booking types: **flight, accommodation, car rental**. Get flights working
end-to-end first (richest case: multi-segment).

## 2. Architecture & data flow

```
DispatcherJob (TimedJob, ~15min, no parallel runs)
  └─ enumerates enrolled users ─→ enqueues UserIngestionJob (QueuedJob) per user
       └─ IngestionService.ingestForUser(uid)
            ├─ IImapClient.fetchRecent()        # READ-ONLY IMAP
            ├─ dedup via ProcessedMessageMapper # by RFC Message-ID
            └─ ILlmService.scheduleText2Text(prompt, uid, customId=messageId)
                 └─ Nextcloud Task Processing (core:text2text), provider = admin-global
TaskSuccessfulEvent / TaskFailedEvent
  └─ ExtractionResultHandler  (correlates by task_id → TaskMap → user+message)
       ├─ ExtractionService.parseAndValidate()  # JSON repair + validation
       └─ BookingService.applyExtraction()      # writes DRAFT bookings (+ details JSON)
UI (Vue, OCS API) — four views: Calendar | Bookings | Trips | Messages
  ├─ Calendar: **the default view**. Month grid, trips and bookings drawn as
  │            bars across the days they cover; ‹ › Today paging, a month-scoped
  │            summary, and a toggle for archived/discarded. Clicking a bar opens
  │            the sidebar *without leaving the calendar*
  ├─ Bookings: sortable grid (Title | Trip | Type | Provider | Reference |
  │            Travel dates | Added | Status); filter by review state + type;
  │            rows do not expand — clicking one opens the detail sidebar
  ├─ Trips:    sortable grid (Trip | Type | Travel dates | Bookings); the Type
  │            column is the trip's own work/leisure, the Bookings column its
  │            dates + booking-type lozenges derived from what is linked; filter
  │            All/Current/Future/Past; rows open the sidebar
  ├─ Messages: the ingestion ledger as a sortable grid (Subject | From | Date
  │            received | Last processed | Attempts | Status), rows expand for
  │            the prompt + raw model response; filter by status, retry
  └─ Detail sidebar: any one booking/trip/message in full, addressed by
               location.hash, linking to whatever it relates to — including a
               "Potential duplicate" section on both sides of a flagged pair —
               and the only place a booking or trip is acted on (its Actions
               section)
```

Key classes (all under `OCA\TravelManager`, `lib/`):
- `Service/ExtractionService` — **pure, dependency-free** prompt build + JSON
  repair + validation, returning `Dto/ExtractionResult` (kept bookings +
  `Dto/ExtractionIssue[]`). This is the most-tested unit.
- `Service/BookingMatcher` — **pure, dependency-free** duplicate detection: does
  this extraction describe a booking the user already has? Takes an
  `ExtractedBooking` plus `Dto/MatchCandidate[]` and returns a `Dto/BookingMatch`
  or null. Second-most-tested unit after `ExtractionService`, and for the same
  reason: it is where the judgement calls live.
- `Service/IngestionService`, `Service/ExtractionResultHandler`, `Service/BookingService`, `Service/ConfigService`.
- `Llm/ILlmService` → `TaskProcessingLlmService` (single platform strategy).
- `Imap/IImapClient` → `HordeImapClient` (read-only, `Horde_Imap_Client`);
  `Imap/Html` is the pure HTML→text helper for HTML-only bodies.
- `BackgroundJob/DispatcherJob`, `BackgroundJob/UserIngestionJob`.
- `Listener/TaskSuccessfulListener`, `Listener/TaskFailedListener`.
- `Db/*` entities + `QBMapper` mappers; `Migration/Version1000Date2026062100…`
  (initial schema) + `Version1000Date2026070300…` (adds the `logs` table) +
  `Version1200Date2026070600…` (drops `segments`, moves type-specific data into a
  per-type `details` JSON column on `bookings`, adds `confirmation_number` +
  `start_date`/`end_date`) + `Version1300Date2026081400…` (splits `status` into
  `status` + `review_state`) + `Version1600Date2026082700…` (adds `messages.sender`)
  + `Version1700Date2026082710…` (adds `messages.issue_reasons`)
  + `Version1800Date2026082800…` (adds `messages.related_booking_ids`)
  + `Version2000Date2026083100…` (indexes `(user_id, type, start_date)` for the
  duplicate-detection candidate query)
  + `Version2100Date2026090100…` (adds `bookings.possible_duplicate_of`).
- `Controller/{Booking,Message,Trip,Settings,Admin,Dev}Controller`; `Settings/*`.
- `Service/IngestionLogger` (per-user activity log) + `Service/MaintenanceService`
  (per-user data wipe) — see §9 (dev/debug tooling).

### Data model (5 tables, prefix `travelmanager_`)
- `messages` — IMAP dedup + ingestion audit + **retry source**; unique
  `(user_id, message_id)`; `status` =
  processing/processed/failed/no_booking/**dropped**/**related** (`related` =
  every booking in it already exists, see §3; see the extraction contract
  below — `dropped` is the retry-worthy one), `failure_kind` =
  schedule/provider/validation, `issue_reasons` = comma-separated
  `ExtractionIssue::REASON_*` slugs from the last attempt (the branchable form of
  what `error` says in prose; cleared on retry), `related_booking_ids` =
  comma-separated ids of the bookings a `related` email matched but did not touch
  (same rationale; cleared on retry), `attempts` counts extraction runs. Holds
  `subject`, `sender` (display form of the From header — grid metadata only,
  never a dedup or classification input; null on rows ingested before it was
  captured, since the envelope is not retained and cannot be backfilled),
  `sent_at` and the plain-text `body_text` that was fed to the model,
  so an extraction can be re-run without going back to IMAP (a message may have
  been deleted, and UIDVALIDITY may have rolled), plus `last_response` — the raw
  model output of the most recent attempt, truncated to 20000 chars, so the
  Messages view can show *what came back* next to the error rather than the error
  alone. `body_text` + `last_response` are the bulky columns: both are meant to be
  dropped once the bookings a message produced are archived.
- `trips` — user-defined grouping. Carries a user-entered **`type`** (work /
  leisure, a slug so the set can grow — validated against `Trip::TYPES`) and
  **`color`** (`#rrggbb`, stored exactly as CSS and NcColorPicker use it).
  Both nullable and **never backfilled or inferred**: nothing in an extracted
  booking says whether a trip was for work, and a guessed lozenge would be a
  confident lie. An unclassified trip simply shows none
  (`Version1900Date20260829000000…`).
- `bookings` — one canonical booking per confirmation. There is **no natural key
  column set**: whether a second email describes an existing booking is a
  judgement made in `BookingMatcher` (see §3), and the DB only supplies
  candidates (`findMatchCandidates`, indexed by `tm_book_user_ref` and
  `tm_book_user_type_start`). `trip_id` links to a trip;
  **`possible_duplicate_of`** points at another of the user's bookings this one
  may duplicate (nullable, not a foreign key — `purge` clears inbound edges
  itself). **Two orthogonal state axes** (see §3):
  `status` = active/cancelled/superseded (the booking *fact*, set from the email)
  and `review_state` = draft/confirmed/discarded/archived (the *user's* decision).
  Cross-type header only (`type, provider, booking_reference,
  confirmation_number, title, status, review_state, confidence, start_date, end_date`); **all
  type-specific structure lives in the `details` JSON column** (see below).
  `start_date`/`end_date` are a denormalised span derived from `details` for list
  ordering.
- `tasks` — Task Processing `task_id` → `(user_id, message_id)` correlation.
- `logs` — per-user pipeline activity log (dev/debug); `(level, step, message,
  context, created_at)`, capped at newest 1000 rows per user.

There is **no `segments` table** — flights/car rentals/hotels have genuinely
different shapes, so each type's structure (flight legs + passengers, car
supplier/features/pickup/dropoff, hotel stay/guests) is stored as validated JSON
in `bookings.details`. The shape can evolve from the prompt + `ExtractionService`
without a DB migration (that is the whole point of the JSON approach).

### Extraction JSON contract
`core:text2text` returns **raw text only** (no JSON/function-calling mode), so
every response goes through `ExtractionService`: strip fences → extract first
balanced `{…}` → `json_decode` → **classify** each booking (`type` in allowlist)
→ validate the per-type `details` (normalise the anchoring date(s), pass unknown
fields through). Anti-hallucination: a booking without its anchoring date is
dropped — flight ⇒ ≥1 segment with a valid `departureLocal`; car_rental ⇒ valid
`pickup.local`; accommodation ⇒ valid `checkIn.local`.

**Malformed JSON is repaired, never silently.** `extractJsonObject` rebuilds the
response while scanning it, keeping a **stack of open containers** (not a depth
counter) so it can insert a closer the model skipped *at the point it skipped
it* — the observed provider failure closes the `bookings` array while a booking
object is still open, so the missing `}` belongs before the `]` and appending at
the end cannot fix it. A repair is accepted only if the result actually parses,
and is **refused** when the response was cut off inside a string (closing the
quote would turn a half-written value into a plausible whole one). Every repair
raises a `repaired_json` issue — a rising repair rate is how a degrading model
shows up before it starts failing outright.

**Rejections are reported, never swallowed.** `parseAndValidate` returns an
`ExtractionResult` (`bookings` + `issues`), where each `ExtractionIssue` carries a
reason slug, a human description (including the unusable raw date, which is what
you tune the prompt against) and whether the whole booking was lost or only part
of it (e.g. a flight that kept 2 of 3 legs). This exists because zero bookings is
otherwise ambiguous: `messages.status` is now `no_booking` when the model
genuinely found nothing versus **`dropped`** when it found bookings we then
refused — only the latter is worth retrying or tuning against. Output shape:
`{ "bookings": [ { type, provider, booking_reference, confirmation_number,
status, title, confidence, details: { …type-specific… } } ] }` — see
`ExtractionService::buildPrompt` for each type's `details` schema.

## 3. Decisions (settled — build to these)

These resolve the brief's V1–V10. Several **override** the original brief; the
code follows the overrides.

- **LLM provider is admin-global** (Task Processing). There is **no** per-user
  local/external toggle and **no** direct strategy in the MVP — that was dropped
  (V2/V9). `ILlmService` remains a thin seam for a future JSON-mode backend.
- **IMAP is strictly read-only** (V3/V6): never flag or move messages. Dedup is
  tracked in the DB keyed on RFC `Message-ID`. The app only *connects* to a
  user-specified mailbox; it does not manage mail setup.
- **Times are local wall-clock** + an informational IANA tz string. **No timezone
  conversion** anywhere (V8).
- **Trips are first-class in MVP** with manual booking→trip linking (auto-clustering deferred).
- **Draft-then-confirm**: LLM output is stored as drafts; nothing is pushed to
  Calendar/Notes until the user confirms (Calendar/Notes projection is deferred).
- **One message = one booking (MVP).** The *first* email about a booking creates
  it. A later email describing the same booking is **reported, never applied**:
  `applyExtraction` returns an `AppliedExtraction` (created + related) holding a
  `RelatedBooking` (id + description + reason slug + whether it was suppressed)
  instead of writing, and the message lands in `messages.status = related` —
  named in its notes *and* linked by id in `related_booking_ids`, so the UI can
  open the booking it matched.
  **Why:** an extraction is a full replacement, not a patch — the old code
  overwrote every column including nulls, so a follow-up email that omitted the
  confirmation number erased it, and a change email listing one leg of a two-leg
  flight truncated `details.segments`. Proper update semantics (which fields
  merge, which replace, what a partial email means) is a design question in its
  own right and is deferred until the simple flow is solid. **Consequence to keep in
  mind:** a cancellation email no longer cancels the booking — it is flagged and
  the user acts. `describeRelated` calls that case out by name.
- **Whether two emails describe one booking is a judgement, not a key.** The
  original rule was a single SQL conjunction on
  `(user_id, type, provider, booking_reference)`. Two real emails walked through
  it, and both are the reason `BookingMatcher` exists — keep them as the tests
  they now are:
  - A hotel booking arrived with `S4RHNGWR` as the reference and `WDP1UANA` as
    the confirmation number; the follow-up called `WDP1UANA` its *reference*.
    **Identifier roles are not stable across senders**, so identifiers are
    compared as a **set intersection**, never positionally.
  - A car hire arrived as `provider = Holiday Autos` (the broker) and then as
    `provider = GOLDCAR` (the desk), with *both* identifiers and both dates
    identical. **`provider` is one column for a two-valued fact**, and `details`
    already carried both names correctly. So provider comparison is a set
    intersection too, drawn from the header *and* the per-type detail fields
    (`supplier`/`rentalCompany`, `propertyName`, segment carriers) — and it is
    never a required term once identifiers agree.

  The rules, in order (`BookingMatcher::match`):
  1. **A shared identifier is decisive**, without consulting provider or dates —
     a rebooking keeps its reference and changes everything else. Identifiers are
     normalised (uppercase, alphanumerics only), floored at 5 characters and
     filtered against a placeholder stoplist. A *short all-numeric* shared
     identifier is the one exception: it must be corroborated by a provider or a
     date, or it drops to rule 3.
  2. **Same operator on the same calendar day is decisive** only when at least
     one side has no usable identifier. Identifiers that *disagree* are positive
     evidence of two different bookings (two rooms, one hotel, one night) and are
     never outvoted. Flights need more even then — same flight number or route —
     because two separately booked one-ways on one airline on one day is a real
     itinerary. Operator names match exactly or by prefix ("KLM" ⊂ "KLM Royal
     Dutch Airlines"), refused when the shorter name is only category words, so
     "Hotel" cannot stand in for "Hotel Sol".
  3. **Ambiguous evidence stores the booking and flags it.** A match suppresses
     the incoming booking outright, so a false positive is the *only*
     irreversible mistake available here — losing a booking beats a duplicate the
     user can discard. Anything short of decisive yields
     `BookingMatch::REASON_POSSIBLE` and the booking is written **with
     `possible_duplicate_of` set** — see the next decision for where that then
     shows up.

  **Dates corroborate; they never veto**, and they are compared by **calendar
  day** — a confirmation gives a 14:00 check-in and the reminder a bare date,
  which `normalizeDate` renders as 00:00. Local wall-clock throughout (V8), by
  comparing the stored strings, never timestamps. `BookingMapper::findMatchCandidates`
  only fetches a **superset** (same type, within ±14 days, or a literal
  identifier hit so a rebooking outside the window is still seen); it must never
  encode the decision. The prompt was tightened in the same change — `provider`
  is defined as the operator that delivers the service, the agency belongs in the
  per-type detail field, and a lone identifier goes in `booking_reference` — but
  the matcher must keep tolerating both readings regardless: the wording only
  lowers the rate, and there is no backfill of existing rows.
- **A possible duplicate is a relation between two bookings, not a fact about an
  email.** It lives in `bookings.possible_duplicate_of`. It was briefly in
  `messages.related_booking_ids`, which was wrong twice over: it could only be
  shown in the Messages view, while the thing to act on is a pair of *bookings*;
  and it made that column mean two things at once — "every booking in this email
  already existed" (the `related` status, genuinely message-level) and "this
  email made a booking that resembles another" — so no one label could be right
  for both. `related_booking_ids` now means only the first, and
  `AppliedExtraction::relatedBookingIds()` filters to **suppressed matches only**.
  Consequences:
  - **Stored one way round, read both ways.** The edge sits on whichever booking
    was created second, but that direction is an accident of processing order.
    `possibleDuplicates` (`src/bookings.ts`) unions both directions, so both
    cards carry the flag and both offer the same way out. Storing it twice would
    be two rows to keep in step for no gain.
  - **Discarded and archived hide the flag rather than clear it.** You have
    already decided about that booking, so there is nothing left to check — but
    restoring it brings the flag back, which is what a soft state is for. Hiding
    is a filter in `possibleDuplicates`, not a write.
  - **"Not a duplicate" is the permanent answer** (`DELETE
    /api/bookings/{id}/duplicate` → `BookingService::clearPossibleDuplicate`),
    and it clears **both** directions — the flag reads the same on both cards, so
    dismissing it on one would leave a lie on the other. Deliberately *not* a
    review transition: the two state axes stay orthogonal, and this is neither a
    fact about the booking nor a decision about keeping it. Without this the flag
    would be an accusation the user can never answer, which trains people to
    ignore the section.
  - **The Messages row banner stays on the second email only**, and is now
    derived from that run's booking carrying an edge (`messageNotices(message,
    created)`), not from the message's own columns. The earlier email's row
    records a run that did nothing wrong; a banner added to it after the fact
    would describe something that had not happened yet. Its *sidebar* still shows
    the "Potential duplicate" section, because that hangs off the bookings.
- **Booking state is two orthogonal axes, never one column.** `status` is a fact
  about the booking (`active`/`cancelled`/`superseded`) and is the *only* one the
  extraction writes; `review_state` is the user's decision
  (`draft`/`confirmed`/`discarded`/`archived`) and is *only* ever written by an
  explicit user action. Flattened into one column these were mutually exclusive —
  a cancelled booking could not also be one you had reviewed. Consequences:
  - **Discard and archive are soft.** The row survives as a tombstone, so the user
    can undo *and* so a later email about the same booking cannot resurrect a
    discarded booking as a fresh draft (a match never writes to the existing row
    at all, `review_state` least of all). **Discarding unlinks the booking from
    its trip**, archiving deliberately does not: discarding says the booking is
    wrong, and leaving it filed would keep a rejected booking feeding the trip's
    derived dates and type lozenges, while archiving says the travel happened and
    is done with — which is precisely when it belongs to its trip, and emptying
    past trips would destroy the history the Trips view and the calendar are
    built on. The link is **not** restored by a later Restore (nothing remembers
    the previous trip), so the discard toast says the booking left its trip
    rather than letting the user find it gone later. Hard deletion is a separate, explicit action
    (`BookingService::purge`, `DELETE /api/bookings/{id}`) — and because it leaves
    no tombstone, a later email *will* re-create the booking.
  - **Archiving is manual** (a user button) for now; an automatic sweep on
    `end_date` + a cooling period is deferred, and is the intended trigger for
    hard-deleting retained email bodies once message-body persistence lands.
  - Review transitions all go through `POST /api/bookings/{id}/review`.
  - **Restore is decided by what you are restoring *from***, not by where the
    booking had been (`restoreTarget` in `src/bookings.ts`, branching on
    `reviewState`; the old branch on `confirmedAt` is gone, and that timestamp is
    now written but never read):
    - **`discarded` → `draft`, always**, even if it was confirmed when you
      discarded it. Discarding is how you say "this is wrong"; taking that back
      means the booking is worth another look, not that it is right. Draft is the
      state that means "needs review", so that is where the second look starts,
      and confirming from there is one click with the trip picker attached.
    - **`archived` → `confirmed`.** Archiving is completion, not rejection — the
      travel happened and you are done with it — so un-archiving must not demand
      that you vouch for the booking a second time. Archive is only reachable
      from confirmed, so there is no other state to return to.

    This is also what keeps trip links coherent: an archived booking keeps its
    trip and comes back to the one state where being linked is legal, while a
    discarded one was unlinked on the way out and returns as a plain unfiled
    draft. `labels.ts::actionLabel` exists for the archived case alone: the
    button targets `confirmed` but must read "Restore", since it is an undo and
    opens no trip picker.
  - **Only confirmed bookings can be linked to a trip.** A trip groups travel you
    have decided is real; a draft is an extraction nobody has vouched for yet, so
    filing one would feed unreviewed guesses into the trip's derived dates and
    type lozenges (which are computed from its bookings — see `tripRows`).
    Enforced in `BookingService::assignBookingToTrip` (400, not a silent no-op)
    and mirrored in the pool `linkDialogBookings`/`unassignedBookings` offer.
    Two deliberate exceptions:
    - **Unlinking (`tripId = null`) is allowed from any state**, and
      `linkDialogBookings` keeps an already-linked booking listed whatever its
      state. A booking linked while confirmed and later restored to draft would
      otherwise be stranded on the trip with no way out of it.
    - Drafts still *reach* a trip, through the picker attached to confirming
      them — which is the moment they stop being guesses. That is why the confirm
      now runs before the link.

    In practice nothing reaches a *linked draft* at all: discarding clears the
    link on the way out, and archive → restore lands on `confirmed`. The
    exceptions above are belt-and-braces for rows that predate these rules.
  - **Confirming a draft asks for a trip first.** Confirmation is the moment a
    booking becomes one you have decided to keep, so it is the natural point to
    group it. The dialog is a **single radio group** over two sections —
    *Suggested* (`suggestedTrips`: trips whose derived span overlaps the
    booking's, ±2 days, so a flight home landing after checkout still matches;
    max 3, nearest first) and *All trips* (`searchTrips` + a `Create "…"` row from
    `canCreateTrip`, plus a pinned `No trip`). **Nothing is pre-selected** and
    Confirm stays disabled until you choose — a pre-selected suggestion would
    link silently on a wrong guess. Typing hides the Suggested section: once you
    search you are browsing deliberately. The section is simply absent when the
    booking has no dates, so the dialog degrades to a plain searchable list.
    The same dialog is reachable from a **Trip** button on any confirmed booking
    — primary with no trip (filing it is the outstanding task), secondary once it
    has one. There it only links (no review change) and re-words to "Add to a
    trip" / "Change trip". A booking that already has a trip opens with it
    **selected**; a draft still opens with nothing selected. `No trip` appears
    only where it does something (confirming, or unlinking an existing trip), and
    one `assignBookingToTrip` call covers link/move/unlink — skipped entirely
    when the choice equals the current trip, so opening the dialog just to look
    writes nothing and shows no toast. Button variants follow the target,
    not the position: `archived`/`discarded` are never the obvious next action so
    they are always secondary, while confirm/restore are primary.
    The review change is applied **before** the link — the reverse of how it was
    first built. Only confirmed bookings can be linked (see below), so linking a
    draft first would simply be rejected (400). The old order existed so a failed link
    left a draft rather than a confirmed booking with no trip; that outcome is
    now handled by the **Trip** button, which every confirmed booking carries and
    which renders *primary* while it has none, so an orphan announces itself and
    is one click from being filed.
    Only `draft → confirmed` opens the dialog: *restoring an archived booking*
    also targets `confirmed`, but it is an undo rather than a first decision and
    it still has the trip it was archived with — hence the `reviewState` check in
    `onReviewAction`, not a target check alone.
- **Failed extractions are retried per message, not by wiping.** `messages`
  retains the email body, so `IngestionService::retryMessage` rebuilds the prompt
  and schedules a fresh task **bypassing the dedup check** (the message is
  processed by definition — that is the point). `POST /api/messages/{id}/retry`;
  the **Messages view** in the app lists the ledger and offers the button.
  Retry is **manual only** for now — automatic bounded retry of
  `failure_kind = provider` (transport/timeout, where retrying verbatim usually
  works) is a deliberate later step. `attempts` is already counted so that
  bound exists when it lands.
- **Background jobs run in system context**, acting on behalf of each user: Task
  Processing tasks carry the `userId`; correlation rides on `customId` + the
  `tasks` table.
- Secrets (IMAP app password) only via `ICredentialsManager`, encrypted, keyed by
  uid — never in app/user config, never logged.

### Dev/debug tooling (Personal settings → "Developer & debugging")
`DevController` (`/api/dev/*`, all `NoAdminRequired`, current-user-scoped) backs a
debug panel for iterating without waiting for cron:
- **Read mailbox now** — `POST /api/dev/ingest` runs `IngestionService::ingestForUser`
  synchronously for the current user (bypasses the dispatcher/enabled fan-out;
  still requires the mailbox to be *configured*). It schedules the async
  extraction tasks; **model responses still arrive later** via the task-event
  listeners, so the UI hint says to refresh the log after a moment.
- **Activity log** — `IngestionLogger` writes a per-user, step-by-step row
  (`connect`/`fetch`/`dedup`/`schedule`/`llm_response`/`persist`/`wipe`) to the
  `logs` table as the pipeline runs; `IngestionService` and
  `ExtractionResultHandler` are instrumented. The prompt sent to the model and the
  raw model response are stored (truncated to 20000 chars) in the row's `context`.
  On a task failure the `context` is a self-contained troubleshooting block
  (provider error + task metadata + the exact prompt sent + any partial output);
  on a parse failure it pairs the validation error with the raw model response.
  Logging is best-effort and never throws into the pipeline. `GET/DELETE
  /api/dev/logs` read/clear it.
- **Wipe my data** — `DELETE /api/dev/data` → `MaintenanceService::wipeUserData`
  deletes the user's bookings, `messages` (dedup ledger) and `tasks` in one
  transaction so the *same* mailbox emails are reprocessed from scratch. Trips
  (manual groupings) and the activity log are **kept**. Confirmed via `NcDialog`.

### Verified NC 33 API facts
- `OCP\TaskProcessing\IManager::scheduleTask(Task)`; `Task(taskTypeId, input,
  appId, ?userId, customId='')`; results via `TaskSuccessfulEvent`/`TaskFailedEvent`
  (`$event->getTask()->getOutput()`, `getErrorMessage()`).
- Task type class is `OCP\TaskProcessing\TaskTypes\TextToText` (ID `core:text2text`).
- Provider selection per task type is **admin-global** (no per-task provider field).
- `Horde_Imap_Client` lives in the **Mail app's** vendor dir, **not** server core →
  we must vendor our own (back-end-only). Mail app is **not** a prerequisite.

## 4. Current state

**Scaffold complete, behind a feature flag, with no live IMAP/LLM calls.**

- ✅ Migration, entities, mappers, services, jobs, listeners, controllers,
  settings panels, Vue UI, DI wiring (`Application.php` + `info.xml`).
- ✅ Psalm clean (errorLevel 1); php-cs-fixer clean; `php -l` clean.
- ✅ `ExtractionServiceTest` (21) + `HtmlTest` (7) pass standalone;
  `bookings.spec.ts` + `messages.spec.ts` (36) pass under vitest.
- ✅ **Real IMAP**: `HordeImapClient` (read-only, `Horde_Imap_Client` vendored)
  is bound as `IImapClient`; smoke-tested install/UI working in a live instance.
- ✅ **Dev/debug tooling** (Personal settings): manual "Read mailbox now" trigger,
  per-user step-by-step activity log, and a data-wipe for reprocessing (see §3).
  Version bumped 1.0.0 → 1.1.0 so the `logs`-table migration runs on upgrade.
- ✅ **Booking lifecycle**: `status` / `review_state` split, soft discard +
  archive + restore, permanent delete (app version 1.3.0).
- ✅ **Extraction issue reporting**: `ExtractionResult` + `messages.status =
  dropped`, so a rejected booking is distinguishable from an email that held none.
- ✅ **Retry**: `messages` retains subject/body, and the **Messages view** lists
  the ingestion ledger with a per-message "Retry extraction" (app version 1.4.0).
- ✅ **One grid for all three views**, sortable column headings over expandable
  rows; `messages.sender` captured from the IMAP envelope (app version 1.6.0).
- ✅ **Provenance**: `DetailSidebar` + hash routing, so a booking, trip or message
  can be opened from anywhere and links back to what it relates to; `related`
  messages carry `related_booking_ids` (app version 1.8.0). See the provenance
  section below.
- ✅ **Duplicate detection is a matcher, not a key** (2026-08-31, app version
  1.11.0): `BookingMatcher` + `Dto/{MatchCandidate,BookingMatch}`, replacing
  `BookingMapper::findByReference`. See the decision in §3 for the two emails
  that motivated it and the rules that came out.
- ✅ **Booking lifecycle tightened** (2026-09-01, app version 1.13.0): restoring
  a *discarded* booking always lands on `draft` (an archived one still returns to
  `confirmed`), discarding unlinks it from its trip, and only confirmed bookings
  can be linked to a trip. See §3.
- ✅ **Potential duplicates are a booking relation** (2026-09-01, app version
  1.12.0): `bookings.possible_duplicate_of`, symmetric display on both booking
  and message cards, and a "Not a duplicate" dismissal. See §3.
- ✅ **Messages reach the model oldest first** (2026-09-01): `fetchRecent` sorts
  the window ascending by UID instead of reversing it, so the earlier email is
  the one that creates a booking. See §7.
- ✅ **Trip picker on confirm**: confirming a draft asks which trip it belongs to,
  with date-based suggestions.
- ✅ **App.vue split** (2026-08-28): 1,829 lines → a 99-line shell plus three view
  SFCs, `store.ts`, `navigation.ts`, `labels.ts` and `grid.css`. See "Frontend
  layout" below. No behaviour change intended.
- ✅ **Trip type + colour** (2026-08-29, app version 1.10.0): `trips.type` and
  `trips.color`, set in the trip editor (an `NcRadioGroup` of buttons, and
  `NcColorPicker`), shown as a lozenge and a swatch on the Trips grid and in the
  detail panel. The colour is not yet used anywhere else — the calendar keeps its
  type palette for now.
- ✅ **Calendar view** (2026-08-29, app version 1.9.0): the app's **default**
  view. A month grid where trips and bookings are bars across the days they
  cover. See "Calendar" below. No backend work — it is a pure client-side view
  over data `store.ts` already holds.
- ✅ **One card, not two** (2026-08-29): Bookings and Trips rows no longer expand;
  the sidebar is the only place either is shown in full or acted on, via a new
  **Actions** section. Dialogs moved to `AppDialogs.vue` + `dialogs.ts`, since the
  panel now raises them. Messages keeps its expander (see "Frontend layout").
- ⏳ **Next step:** run flights **end-to-end** for one user against a live
  mailbox + Task Processing provider (the path is all wired — ingestion →
  schedule → listener → draft; use "Read mailbox now" + the activity log to watch
  each step), then tune the extraction prompt against the `dropped` rows in the
  Messages view, then enable multi-user fan-out. PDF e-ticket attachments and
  Calendar/Notes projection remain deferred.

Build/implement order from here: end-to-end flight extraction (single user) →
prompt tuning on real emails → **archive sweep + `body_text`/`last_response` GC**
→ multi-user fan-out.

### Provenance: message ↔ booking ↔ trip

**The user must always be able to see where a piece of information came from.**
Treat navigable links between the three entities as a first-class goal, and weigh
any design decision against whether it preserves or destroys that trail — this is
a recurring consideration, not a one-off feature request.

**Built (phase 1).** `DetailSidebar.vue` is the one place a booking, trip or
message is shown in full, openable from any view — and later from the calendar,
which is why *what is open* is a value rather than a component's internal state:
- **Addressing lives in `location.hash`** (`src/detail.ts`: `parseRoute` /
  `formatRoute` / `detailRoute`) — `#/bookings/42`, `#/trips/7`, `#/messages/19`.
  No vue-router; the route set is tiny and fixed. A malformed hash falls back to
  `DEFAULT_ROUTE` rather than rendering nothing.
- **Back is the browser's own history**, not a second stack: navigating pushes an
  entry, the panel's "←" calls `history.back()`, and its caption rides in
  `history.state.fromLabel` so it survives Back *and* Forward. A ref would
  desync the moment someone used the browser's button.
- **Two ways in, and they differ.** `openDetail` (a list row) clears the trail;
  `openLinked` (a cross-link inside the panel) keeps it. Clicking Trip 2 in the
  list while Trip 1 is open must not offer "← Trip 1" — that describes a journey
  the user did not take. Browser Back still returns either way; it is only the
  panel's own back affordance that resets.
- **`NcAppNavigationItem` renders `<a href="#">`** when given no `href`/`to`, so
  every nav click appends a bare `#` to the history and fires `popstate`. Two
  defences, keep both: the nav items use **`@click.prevent`**, and `onPopState`
  uses **`matchRoute`** (null for anything unrecognised) rather than `parseRoute`
  (which falls back to Bookings). Without them, clicking Trips switched the view
  and the stray hash switched it straight back — the view nav silently stopped
  working.
- `sourceMessageId` is on the booking payload; **`GET /api/messages?messageId=`**
  looks up a source email older than the list's 200-row page (a query param, not
  a path segment — an RFC Message-ID contains `@` and angle brackets, and our OCS
  helper does not escape path segments).
- `BookingDetails.vue` renders a booking's type-specific body. It was shared with
  the expanded grid row until that row was removed; keep it a separate component
  anyway — the calendar will want the same body.
- The panel deliberately has **no tabs**: cross-links are the whole point of the
  feature, and a tab would hide them behind a click.

**Built (phase 2).** `related` messages now carry the ids of the bookings they
matched in **`messages.related_booking_ids`** (`Version1800Date2026082800…`,
comma-separated like `issue_reasons`; cleared on retry). `BookingService::applyOne`
returns a **`RelatedBooking`** DTO (id + description) instead of a bare string, so
the relationship survives as data rather than only inside the sentence
`describeRelated` writes into `messages.error`. The sidebar's message panel shows
it under **"Relates to"**, kept separate from "Bookings from this email" — *made
this* and *is about this* are different claims and merging them would misreport
what the app did. No backfill: the old rows' ids are recoverable only by parsing
prose, and re-running the message fills the column properly.

### Calendar

The month grid, and the view the app opens on. Built on the same principle as the
rest: *what is open* is a value in the URL, the layout maths is pure and tested,
and the wording lives in `labels.ts`.

- **Clicking a bar does not leave the calendar — except for a message.** The hash
  grammar therefore has a second form: `#/calendar/bookings/42` alongside the
  existing `#/bookings/42` shorthand. `keepsView(view, type)` takes the *type* as
  well as the view for this reason: a message is an email, not something that
  happens on a day, so holding the month behind one shows a grid with no bearing
  on the panel. Opening a message hands over to the Messages list, which
  **expands the row on arrival** (`revealRoutedMessage`) — the prompt and the raw
  model response live there and nowhere else. That nudge is imperative and
  `onMounted`-only: a `:open` binding would fight the user's own collapsing, and
  running it on every route change would merge the list's two deliberately
  separate affordances (summary click toggles, subject click opens the panel). `detail.ts` grew `ViewName`, a three-segment branch in `matchRoute`,
  a `within` argument on `detailRoute`, and **`keepsView(view)`** — true only for
  the calendar. `formatRoute` **collapses back to the shorthand** whenever the
  entity is shown over its own list, so existing URLs keep working *and* keep
  being the ones we generate. **Why the calendar is special:** the lists are each
  about one kind of thing, so opening a different kind there genuinely means you
  have left; the month is the thing you work *from*, and taking it off screen on
  every click makes stepping through a trip a round trip through the Bookings
  list each time. `DEFAULT_ROUTE` is now `#/calendar`.
- **`NcAppSidebar` shrinks the content area, it does not cover it**
  (`position: relative`, `width: clamp(300px, 27vw, 500px)`, and `.app-content`
  has `min-width: 0`; below 512px it becomes a full-screen overlay). At a 1512px
  viewport the grid keeps ~800px with the panel open. That squeeze is safe *here*
  in a way it is not for the list grids: every bar's day and lane are fixed by its
  dates, so nothing moves to another day — only the label truncates.
- **Compaction is a `@container` query, not a media query.** The viewport does not
  change when the sidebar opens, so a media query never fires at the one moment
  the grid most needs to compact. `.tm-cal` is the container; below 640px the bar
  labels drop and the icon carries the meaning. This is why every bar has an
  **`aria-label`**: `display: none` takes the visible label out of the
  accessibility tree too, so the button would otherwise be unnamed exactly when it
  is hardest to identify by sight.
- **A trip's colour takes over its bars.** A trip states its colour outright; its
  bookings take a **paler** version — `color-mix` toward `--color-main-background`,
  so it pales on a light theme and darkens on a dark one instead of glaring.
  `CalendarItem.color` carries the raw colour (its own for a trip, its trip's for a
  booking, null for an unfiled booking or an uncoloured trip, which then fall back
  to `--color-primary-element`); `CalendarBar` passes it through as `--tm-cal-trip`
  and
  **paling is left to CSS**, since only the stylesheet knows what the background
  is. Three consequences:
  - **A trip bar's text colour is computed** (`contrastingText`). The type palette
    is picked to carry white at ~5:1, but a trip colour comes from Nextcloud's
    picker, which offers pale yellows — white on those is unreadable. A *booking*
    bar needs no such computation: a fill that close to the background simply takes
    `--color-main-text`.
  - **A pale bar gets a border** of a stronger mix of the same colour. Without one
    it has no edge and floats on the day cell.
  - **There is no booking-type palette any more.** Colour on this view means one
    thing only — which trip — so a bar with no trip colour takes the theme accent
    rather than a per-type one, and an uncoloured trip and its bookings read as
    one family exactly as a coloured one does. **This is why every bar has a type
    icon**: it is now the only thing that says what kind of booking it is.
- **Type is carried entirely by the icon** (`vue-material-design-icons`: plane /
  bed / car, MapMarker as the fallback), since colour now means which trip. Every
  bar has one for exactly that reason.
- **Draft is a dashed outline**, not a paler shade — a paler shade is what a
  *booking* already is, and the cue has to survive greyscale and colour-blindness
  besides.
- **Trip against booking is fill strength, weight and icon** — full colour, bold,
  suitcase — **not shape**. Bars all share one corner radius; a pill for trips
  made the distinction louder than it needs to be once the fill carries it. (The
  one remaining `--border-radius-pill` in calendar.css is the today circle.)
- **Colours adapt to the theme rather than being duplicated per theme.** The trip
  fill is the user's own or `--color-primary-element`; a booking's is mixed toward
  `--color-main-background` and a draft's edge toward `--color-main-text`, so both
  follow light and dark with no theme hook to keep in step.
- **A multi-leg flight draws one bar per leg**, each spanning that leg's own
  departure→arrival dates (`bookingItems`/`flightLegItems`). `bookings.start_date`
  /`end_date` is the *whole itinerary*, so a return trip drawn from it is a single
  bar covering the fortnight you were away — which says nothing about when you were
  flying and buries every other booking under it. Consequences:
  - `CalendarItem.id` is **not unique** (all four legs carry the booking's id, so
    any leg opens it and all four highlight together); **`CalendarItem.key`** is,
    and is what the `v-for` keys on and what `compareItems` breaks ties by. An
    id-based tiebreak is not a total order and let legs swap lanes between renders.
  - **`monthSummary` counts distinct ids, not bars** — a four-leg flight is one
    booking you have to make one decision about.
  - Each leg is labelled by `segmentLabel` ("EY42 AMS → AUH"), not by the booking
    title, which would otherwise repeat a long subject line four times. The flight
    number is preferred over the carrier because it already carries the airline
    code. Single-leg flights get the same treatment, for consistency and because
    a route beats a truncated email subject in a ~150px cell.
  - Falls back to the stored span when a flight has no readable segments, and
    skips an individual leg with no `departureLocal` (the field extraction
    validates a segment by). Hotels and car rentals are never split — a stay is
    one continuous thing.
- **Trip bars use the derived span** (`tripRows`/`tripSpan`), never
  `trips.start_date` — same rule as the Trips grid, so the two cannot disagree.
  A trip with no dated bookings has no span and simply is not on the calendar.
- **Discarded and archived are hidden by default.** On a list they merely sit
  there; here they would take lanes from the bookings that matter. An
  `NcCheckboxRadioSwitch type="switch"` in the toolbar reveals them — a switch
  rather than a button because it turns a persistent condition on and off rather
  than performing an action, and its state then reads directly instead of having
  to be inferred from a button's variant.
- **The month summary is scoped to the month on screen** ("2 trips · 5 bookings ·
  1 draft"), which is the one count the navigation's global counters cannot give.
  The draft count is a **button** that opens the earliest draft in the month, so
  it is something you act on rather than a number you carry elsewhere.
- **Week rows are `minmax(min-content, 1fr)`, never `minmax(<length>, 1fr)`.** A
  `1fr` *maximum* is a flexible track: it is sized by distributing free space and
  never by what is in it, so a fixed minimum lets a week with more lanes than its
  share overflow into the week below — bars landing on the next row's day numbers,
  with no warning. Measured: a row needing 226px given 119px spills 107px. With
  `min-content` the floor is what the week actually needs and the flex share still
  fills the screen. `--tm-cal-week-min` moved onto `.tm-cal-week` accordingly,
  where it feeds that min-content instead of capping it, and now means only "the
  floor an empty week sits at".
- **The lane geometry is declared once, in CSS, as explicit lengths.**
  `--tm-cal-bar-height: 20px`, `--tm-cal-lane-gap: 2px`, `--tm-cal-head: 22px`
  (the day-number row) and `--tm-cal-foot: 3px` are set on `.tm-cal` and *applied*
  as real `height`s, not left to however a line box rounds. `CalendarView` reads
  all four back with `getComputedStyle` to work out how many lanes fit, so the
  arithmetic and the pixels cannot drift. Change the bar height and everything
  follows; hard-code a pitch in JS and it will not.
- **The lane cap is measured, not a constant** (`lanesForHeight`). A week row is
  `minmax(min-content, 1fr)`, so its height depends on the window and on how many
  weeks the month has — anywhere from ~114px to ~250px. A fixed cap says "+3 more"
  over visible empty space on a tall screen. `CalendarView` observes the grid with
  a `ResizeObserver` and feeds the *equal share* (grid height ÷ weeks) in; a
  measured row would be circular, since the cap decides the content and the content
  decides the row. Observing the grid is safe because its height comes from the
  window (it is the `flex: 1` child that scrolls), so the cap never feeds back.
  - **`MIN_LANES` (6) is a floor the share can only raise.** Because a row grows
    past its share and the grid scrolls, capping at the nominal share would hide
    bookings the layout was perfectly willing to draw.
  - `DEFAULT_MAX_LANES` is now only the value for the first render, before the
    observer has reported.
- **`+N more` expands the week in place** rather than opening a popover: the
  hidden items stay on the day they belong to and there is no floating layer to
  dismiss. `layoutMonth` takes a per-week lane cap for exactly this. The cap is
  **soft** — a week needing one lane more keeps it, since "+1 more" would occupy
  the very row it saved.
- **The grid stays even when empty** — a calendar with no days is not a calendar —
  so the empty case is a line in the summary, not an `NcEmptyContent` replacing
  the view as the lists do.
- **Day backgrounds are their own layer** (`.tm-cal-week-bg` absolutely positioned
  under `.tm-cal-week-fg`). A cell spanning every lane inside one grid would need
  the lane count in `grid-template-rows`, and `repeat()` cannot take a custom
  property.
- **The month heading's hidden `.tm-cal-month-ghost` spans are load-bearing.**
  The `<h2>` is a one-cell grid holding the visible label *and* a hidden copy of
  every `getMonthNames()` entry, so it is always as wide as the widest month it
  could show and the ‹ › Today buttons never shift as you page (measured: 12
  distinct button positions before, 1 after). `visibility: hidden`, never
  `display: none` — a hidden box still sizes the grid, which is the whole point,
  and it stays out of the accessibility tree so the heading reads as one month.
  A hard-coded `min-width` cannot replace this: month-name lengths differ wildly
  by locale and font. `tabular-nums` stops the box twitching on a year change.
- Week start and day/month names come from `@nextcloud/l10n` (`getFirstDay`,
  `getDayNamesMin`, `getMonthNames`), so a Sunday-first locale is right for free;
  `calendar.ts` takes `firstDay` as a **parameter** so it stays pure and tested.
- The displayed month is a component ref, **not** in the hash. It survives view
  switches (the module stays mounted) but not a reload — deliberate for now; put
  it in the route if month links ever need sharing.

### Frontend layout (`src/`)

```
App.vue              shell only: nav, which view is showing, the detail panel
├─ CalendarView.vue  the month grid, its toolbar and month summary
│   └─ CalendarBar.vue    one trip/booking bar: its icon, colour and draft cue
├─ BookingsView.vue  grid only — no dialogs, no actions
├─ TripsView.vue     grid + the "Create trip" button
├─ MessagesView.vue  grid + the expandable diagnostic body
├─ DetailSidebar.vue  one panel for booking | trip | message, incl. its Actions
│   └─ BookingDetails.vue   a booking's type-specific body
└─ AppDialogs.vue    every dialog, rendered once
    └─ TripPickerDialog.vue   confirm-a-draft / add-to-trip

grid.css        the look every list grid shares (see below)
calendar.css    the month grid's own look, shared by the two calendar SFCs
store.ts        bookings / trips / messages / loading / reload + derived counts
navigation.ts   the route, open/close/back, hash + history plumbing
dialogs.ts      which dialog is open, on what, and the openX() calls
labels.ts       how the domain is worded
grid.ts messages.ts bookings.ts trips.ts detail.ts calendar.ts   pure, unit-tested
```

Adding the fourth view (the calendar) cost exactly what that promised: one nav
item and one SFC in `App.vue` (plus the bar it delegates to, its pure layout
module and its stylesheet). Hold a fifth to the same bar.

`calendar.css` is plain CSS rather than a `<style module>` for the same reason
`grid.css` is: **two** components need it — `CalendarView` draws the grid and
`CalendarBar` draws what sits on it, positioned by the *view* via inline
`grid-column`/`grid-row`, so both files have to agree about the same element.

- **`store.ts` holds module-level refs**, not provide/inject or prop threading.
  Every view, the sidebar and most dialogs need the same three collections and
  the same `reload()`. Safe because the app mounts once; revisit if that changes.
- **`dialogs.ts` is the same pattern for *what is open*, and `AppDialogs.vue`
  renders the lot.** Most dialogs are now raised from the detail panel and one
  ("New trip") from the Trips toolbar, so no view owns them; threading them
  through `App.vue` would put the shell in the middle of every action. It holds
  only refs and `openX()` calls — what a confirm button *does* lives in the SFC.
- **`labels.ts` is the one module that may import `@nextcloud/l10n`** and so is
  *not* unit-testable (§7). That is the trade for every view wording things
  identically. Keep decisions out of it — anything with a branch belongs in a
  pure module where it gets tested.
- **Each view owns its own filter/sort refs.** Do not conflate view and filter
  again — the original single `filter` ref made "All bookings" a status and
  "Trips" a view. All filtering/sorting is client-side and pure
  (`sortBookings`/`filterBookings`, `sortMessages`/`filterMessagesByStatus`,
  `sortTrips`/`filterTripsByPeriod`/`tripRows`, shared helpers in `src/grid.ts`).

**All three list views are the same grid** (the calendar is not one of them — see
"Calendar" above). The shared look lives in **`src/grid.css`**
as plain `tm-`-prefixed classes (`.tm-rows`, `.tm-row`, `.tm-row-summary`,
`.tm-grid-header`, `.tm-column-heading`, `.tm-chevron`, `.tm-row-body`,
`.tm-cell-text`/`.tm-cell-meta`/`.tm-cell-status`, `.tm-open-link`, `.tm-badge…`,
`.tm-list…`), imported once by `App.vue`. Plain CSS rather than a module because
`<style module>` is per-SFC and all three views need these. Each view's own
`<style module>` holds **only** its `grid-template-columns` (`.columns`) and the
`nth-child` rules that drop columns at a breakpoint. Sort behaviour is shared via
**`src/grid.ts`** (`SortColumn`, `SortDirection`, `nextSortDirection`,
`sortMarker`, `formatSpan`, `formatTimestamp`, `localDate`) so no view's module
imports another's. A change to how every grid looks belongs in `grid.css`, never
copied into a second set.

It is a CSS grid, not a `<table>`. Consequences to preserve:
- **Only Messages rows expand.** Bookings and Trips rows are a plain `<div>`
  carrying both `.tm-row` and `.tm-row-summary`; clicking anywhere opens the
  detail sidebar, and every action lives in that panel's **Actions** section.
  Their row bodies used to be a strictly poorer copy of the panel — two places to
  keep a field in step, and the one most people saw carried **no cross-links at
  all**, which is the trail the panel exists to preserve. Messages keeps its
  `<details>` because its body is a *different thing*, not a second copy of the
  card: the prompt and up to 20 000 characters of raw model response, which is
  unreadable in a ~300px sidebar and is the pane prompt-tuning runs on.
  Do not "finish the job" by moving it there.
- **The row click is a convenience, the first cell's `<button>` is the control.**
  Keyboard and screen-reader users get the button; the row handler exists so a
  mouse does not have to hit the text. Do not promote the row to
  `role="button"` — that would promise a widget the markup is not.
- The heading row and each data row use the **same `.tm-row-summary` + `.columns`
  pair and the same cell order**, so one set of `nth-child` rules hides a column
  in both at a breakpoint. (Messages' heading therefore keeps a real empty
  element for the chevron cell — do not replace it with an offset.) Reordering
  columns means editing three things in step: the `*_COLUMNS` array, the cell
  order in the template, and the `nth-child` breakpoint rules.
- **The first data column is the row's identity and the link into the sidebar**
  in all three grids (Subject / Title / Trip). Keep it first if columns are
  reordered.
- **`sortBookings('travel', 'asc')` is deliberately not a plain date sort**: next
  trip first, then further-off ones, *then past travel in reverse*, undated last.
  Ascending by date would bury what is coming up under everything that already
  happened. Descending is plain reverse chronology (looking back, not ahead).
- **`sortTrips('travel')` is the opposite call, on purpose**: a plain chronology,
  because the Current/Future/Past chips already answer "what is coming up" and
  re-ranking the column around today as well would fight them.
- **Trip dates and type lozenges are derived, never read from `trips.start_date`/
  `end_date`** (`tripRows`/`tripSpan` in `src/trips.ts`): the stored columns are
  user-entered and go stale the moment a booking is linked or unlinked, so the
  grid would disagree with the rows underneath it. A booking with no end date
  contributes its start, so a one-day hire still extends the trip. **Period and
  upcoming/past comparisons are by calendar date, via `localDate(now)`** — never
  by full timestamp (a 15:20 departure showed as "Future" all morning) and never
  via `toISOString()` (that is the UTC date, wrong either side of midnight for
  most of the world; travel times are local wall-clock with no offset, V8). A
  trip's
  bookings are listed in **travel order** (`inTravelOrder`, undated last): the
  API returns them `created_at DESC`, which is the order the *emails* arrived and
  says nothing about the trip. `TripPeriod`
  keeps **`undated`** as its own case — such trips appear under **All only**,
  because filing them under a period would make the period filters lie.
- Headings are plain `<button>`s with a ▲/▼ marker, **not** `role="columnheader"`
  + `aria-sort`: grid ARIA would promise a table structure the markup does not
  have. Sort state is a `sort` + `direction` ref per view; the `*_COLUMNS` array
  holds order and each column's default direction, and `nextSortDirection`
  implements "default on a new column, flip on the same one".
- Column **labels live in the view SFC** as literal `t()` calls (extractable),
  keyed off its `*_COLUMNS` array; the logic modules stay `@nextcloud/*`-free.
- Rows with no value for the sorted column sink to the bottom in **both**
  directions — unsortable, not "smallest".
- **Label/value blocks inside one container share a label column** by being
  flattened into a single grid: mark the container `.tm-fields-group` and its
  blocks collapse with `display: contents` (`grid.css`). Left as separate grids
  each sizes to *its own* longest label, so values start at a different x per
  block; a fixed width aligns them but reserves room for the longest label the
  app can produce, wasting it on every card where that label is absent. This is
  why `BookingDetails` uses `tm-` classes on its wrappers and why its separators
  (`.tm-divider`, `.tm-gap`, `.tm-segment-index`) are **real elements** — a
  flattened wrapper has no box to hang a border or margin on.
- An expanded row opens with `NcNoteCard` notices from `messageNotices()`:
  failure kind (error) → outcome for related/no_booking/dropped (info/info/warning)
  → a warning when `issueReasons` contains `repaired_json`. A row can carry
  several — a booking can be saved *and* have come from a repaired response.
  **`repaired_json` is why `messages.issue_reasons` exists**
  (`Version1700Date2026082710…`): the slugs were already in `error`, but only as
  prose (`- [repaired_json] …`), and parsing our own sentences back out on the
  client would have made that wording a wire contract.
- An expanded row shows the two halves of a diagnosis — **Raw message** (what we
  sent) and **Model response** (what came back) — each with a Copy button
  floating in its box. `body_text` stays **out of the list response** (bulky:
  20000 chars × up to 200 rows); the Raw message section fetches it lazily from
  **`GET /api/messages/{id}/body`** on first expand and caches it by id. Do not
  fold it back into `jsonSerialize()`.

## 5. Prerequisites (runtime)

- Background jobs in **cron / systemd** mode (not AJAX).
- An AI text-processing provider for `core:text2text` installed and selected in
  the **AI admin settings** (`llm2` local and/or `integration_openai` external).
  Email content is sent to whichever provider the admin configured.
- Optional **AI background worker** (systemd) for acceptable latency.
- A per-user dedicated mailbox reachable by IMAP with an **app password**
  (OAuth2/XOAUTH2 for Gmail/M365 is deferred).

## 6. Dev workflow

```sh
composer install        # PHP deps + dev tools (bamarni bin plugin)
composer psalm          # static analysis, errorLevel 1, findUnusedCode
composer cs:fix         # php-cs-fixer
composer test:unit      # PHPUnit — runs inside a Nextcloud server checkout

npm ci                  # frontend deps (needs package-lock.json — committed)
npm run lint            # eslint
npm run type-check      # vue-tsc --noEmit
npm run stylelint       # stylelint
npm run test:frontend   # vitest (tests/frontend/**)
npm run build           # vite build (Vue 3 + @nextcloud/vite-config)
```

A **`Makefile`** wraps these for build/package/deploy. Key targets: `make dev`
(install everything), `make lint`, `make test`, `make psalm`, `make build`
(frontend + isolated prod composer install), `make package` (app-store zip from
`git archive HEAD`), `make stage` (deployable tree from the **working tree**,
including uncommitted changes — rsync to a test server), `make clean` /
`make distclean`. `make check` = lint + psalm + test + openapi. Note `make test`
and `make check` include `composer test:unit`, which only passes inside a full
Nextcloud checkout (see §7); run those in CI / a dev server.

## 7. Gotchas (learned the hard way — read before editing)

- **OCP is static-analysis stubs, not runtime classes.** `OCP\AppFramework\Db\
  Entity`, `QBMapper`, `IRequest` etc. are **not runtime-autoloadable** from this
  repo alone. Therefore tests that mock mapper/controller/OCP types only run
  inside a full Nextcloud server checkout (via `tests/bootstrap.php`, like the
  skeleton `ApiTest` and `IngestionServiceTest`). **Keep extraction/parsing logic
  free of OCP types** so it stays unit-testable standalone (that is why
  `ExtractionService` uses literal type strings, not `Booking::TYPE_*`).
- Run the pure tests standalone with:
  `php vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php --no-configuration tests/unit/Service/ExtractionServiceTest.php`
- **Psalm** is `errorLevel=1` + `findUnusedCode=true`. DI-instantiated classes,
  `info.xml`-registered classes (jobs/settings/migration) and reserved public API
  look "unused" — these are captured in `tests/psalm-baseline.xml`. Entities carry
  `@psalm-suppress PropertyNotSetInConstructor` for the base `$id`. Regenerate the
  baseline with `psalm --set-baseline=tests/psalm-baseline.xml` only for genuine
  framework false-positives; fix real issues instead of baselining them.
- **`doctrine/dbal`** is a dev-dependency purely so Psalm can resolve the
  `Doctrine\DBAL\Schema\Table` type that `ISchemaWrapper` returns in migrations.
- **App config:** use `IAppConfig` (`getValueBool/Int`, `setValue…`) —
  `IConfig::getAppValue/setAppValue` are **deprecated** and Psalm flags them.
  **User config uses `OCP\Config\IUserConfig`** (`getValueString/Int/Bool`,
  `setValueString/…`, and `searchUsersByValueString` for the enrolled-user
  fan-out). As of **NC 33** the old `IConfig::{get,set}UserValue` /
  `getUsersForUserValue` are **deprecated** (Psalm errorLevel 1 flags them) — do
  not reintroduce them. `ConfigService` stores per-user values as **strings** to
  keep the wire-format stable (so the `'1'` enabled-flag fan-out keeps matching).
- **Task Processing:** use `getAvailableTaskTypes()` (long-standing) to probe for
  a provider. `getAvailableTaskTypeIds()` is a recent addition (server PR #54848)
  and may not exist on NC 33.
- **Settings registration** is via `info.xml` `<settings>` (admin/admin-section/
  personal/personal-section) and `<background-jobs>`. **Bump the app version** when
  adding settings so Nextcloud re-registers them.
- **Every routed controller method needs full OpenAPI docblocks** or
  `composer openapi` (and the `openapi.yml` CI / `make openapi`) fails. Required:
  a one-line summary (no trailing period), a `@param` for each parameter, a typed
  `@return DataResponse<Http::STATUS_…, T, array{}>`, a `@throws OCS…Exception`
  for each error path (mapped: NotFound→404, BadRequest→400, Forbidden→403), and
  matching `NNN: description` status lines. Shared response shapes live as
  `@psalm-type` in `lib/ResponseDefinitions.php` and are pulled in with
  `@psalm-import-type … from \OCA\TravelManager\ResponseDefinitions`; the matching
  entity/DTO `jsonSerialize()` carries the same `@return` shape so psalm stays
  green. `array_map` over a list is `array<array-key,…>`, not `list<…>` — wrap in
  `array_values(...)` when the spec type is `list<…>`. Mark internal-only
  controllers `#[OpenAPI(OpenAPI::SCOPE_IGNORE)]` instead (as `PageController` does).
  Regenerate and commit `openapi.json` whenever an endpoint changes.
- **Frontend entries:** `vite.config.ts` entry keys map to output names
  `travelmanager-<key>`; PHP `Util::addScript('travelmanager', 'travelmanager-<key>')`
  must match. (The skeleton shipped `vite.config.ts` pointing at a non-existent
  `main.js` and an invalid empty `composer.json` author homepage — both fixed.)
- **Frontend lint config (skeleton shipped the wrong variants — fixed).** This is
  a **Vue 3 + TypeScript** project, so `.eslintrc.cjs` must extend
  `@nextcloud/eslint-config/vue3` (the base `@nextcloud` config is JS-only and
  **fails to parse `<script lang="ts">`**), and `stylelint.config.cjs` must extend
  `@nextcloud/stylelint-config` (extending the transitive
  `stylelint-config-recommended-vue` yields "No rules found"). eslint is v8
  (legacy `.eslintrc`, not flat config).
- **`@nextcloud/vue` 9 API drift** — some of this is **runtime-only** (type-check
  and eslint pass, the component just silently doesn't work), so test in the
  browser too:
  - `NcButton` styling prop is **`variant`** (`primary`/`secondary`/`tertiary`/…),
    **not** `type` (now the native button type). *(type error — caught by vue-tsc)*
  - **No `.sync`** in Vue 3 — use `v-model`/`v-model:propName`. **Field
    components take plain `v-model`, NOT `v-model:value`.** In v9
    `NcTextField`/`NcInputField`/`NcPasswordField` emit **`update:modelValue`**
    (standard `v-model`); they keep a deprecated `value` prop for compat, so
    `v-model:value` *type-checks* but binds the wrong prop and listens for
    `update:value`, which **never fires** — the input neither shows the reactive
    state nor writes back to it, so forms silently submit stale/empty values.
    Use `v-model="form.field"`. **Runtime-only failure — not caught by vue-tsc.**
  - `NcCheckboxRadioSwitch` migrated from `:checked` + `@update:checked` to
    **`v-model`** (`modelValue`/`update:modelValue`). The old `:checked` still
    binds the initial state so it *looks* fine, but `@update:checked` never fires
    → the control won't toggle. **Runtime-only failure — not caught by vue-tsc.**
  - When in doubt, check the component's `update:*` emit in
    `node_modules/@nextcloud/vue/dist/chunks/Nc*.mjs`.
- **Trip type and colour are cleared with `''`, not `null`.** `TripController::update`
  filters out nulls so an omitted field keeps its value; that makes null mean
  "not supplied", so the empty string has to carry "clear it". `BookingService`
  treats `''` as null on the way in. The frontend keeps `''` throughout for the
  same reason — except at `NcColorPicker`, which models `string | undefined` and
  treats `''` as neither a colour nor none, so it is mapped at that one boundary.
- **Nextcloud's core stylesheet styles every bare `<button>`, hard.**
  `core/css/inputs.scss` matches
  `button:not(.button-vue, [class^="vs__"]):not(.app-navigation-entry-button)` —
  **(0,2,1)**, which outranks any plain two-class app selector — and sets
  `min-height: var(--default-clickable-area)` (34px in the stable34 theme),
  `margin: 3px`, `width: 130px`, padding, font-size, background and border. Its
  `:hover`/`:focus` variants reach **(0,4,1)**. Symptoms are a control silently
  far taller than its own CSS says (this made every calendar bar 34px against an
  18px design, and once did the same to grid rows — see `.tm-open-link`).
  - Where a control is genuinely a **link**, make it an `<a href>`: the server
    styles bare anchors *not at all*, which ends the arms race permanently rather
    than escalating specificity against rules that can change under us. The
    calendar's bars do this — they navigate to a route, so it is also the honest
    element. Keep `@click.prevent` and handle the click in JS: our router listens
    for `popstate` and a fragment link fires only `hashchange`.
  - Where it is genuinely a **button** (`.tm-cal-more` expands a week), undo the
    reset explicitly and **double the class** to clear (0,2,1).
- **Verify CSS against the server's own stylesheets, not just its variables.**
  A harness that defines `--color-*` and `--default-clickable-area` but omits
  `core/css/inputs.css` will happily measure a design that the real page does not
  produce — that is precisely how the 18px bar was "confirmed" while shipping at
  34px. A server checkout lives at
  `~/Code/nextcloud-docker-dev/workspace/stable34`; link its `core/css/inputs.css`
  and `apps.css` into any layout harness before trusting a measurement.
- **`vue-material-design-icons` ships types TypeScript cannot see.** Declarations
  sit beside each component (`Airplane.d.vue.ts`), but the package's
  `exports` map does not list them, so every icon import is an implicit `any` and
  `vue-tsc` fails under `noImplicitAny`. Fixed by a wildcard shim,
  **`src/vue-material-design-icons.d.ts`** (`declare module
  'vue-material-design-icons/*.vue'`) — every icon in the set takes the same three
  props (`title`, `fillColor` defaulting to `currentColor`, `size`). Delete the
  shim if the package ever fixes its exports map. Note `fillColor`'s default is
  what lets CSS `color` drive an icon, which the calendar's bars rely on.
- **`@nextcloud/dialogs` must be ^7** (v6 pins a `@nextcloud/vue ^8` peer that
  conflicts with v9 and blocks `npm install`). v7 dropped the `@nextcloud/vue`
  peer. Same story for **`@nextcloud/password-confirmation` — must be ^6** (v5
  pins Vue 2 / `@nextcloud/vue ^8`; v6 dropped the peers and is Vue-3 clean).
- **OCS URL helper must not encode path slashes.** `generateOcsUrl(url, params)`
  runs `encodeURIComponent` on every `{param}` (option `escape` defaults to
  `true`), so passing a multi-segment path as one param —
  `generateOcsUrl('apps/travelmanager/api/{path}', { path: 'dev/logs' })` — yields
  `dev%2Flogs` and the route 404s. Only single-segment paths survive. Interpolate
  the sub-path straight into the template instead:
  `generateOcsUrl(\`apps/travelmanager/api/${path}\`)` (all our paths are
  app-controlled with numeric ids, so no escaping is needed). See `base()` in
  `src/api.ts`.
- **`#[PasswordConfirmationRequired]` needs a frontend confirm step.** A method
  marked `PasswordConfirmationRequired` (we use it on `SettingsController::update`,
  which writes the IMAP app password) returns **403** unless the user re-confirmed
  their password within the sudo window (~30 min; login counts, so it passes right
  after sign-in then starts failing). Call **`await confirmPassword()`** from
  `@nextcloud/password-confirmation` before the request (it only prompts when
  needed; treat a rejection as the user cancelling). See `onSave()` in
  `src/PersonalSettings.vue`.
- **Native UI, not browser dialogs.** `@nextcloud/dialogs` is for **toasts only**
  (`showError`/`showSuccess`). For prompts/confirms use the **`NcDialog`**
  component (with `NcTextField`/`NcButton` in the `#actions` slot) controlled by a
  reactive `open` ref — never `window.prompt`/`window.confirm`/`window.alert`
  (they render as ugly browser-chrome dialogs). See the "New trip" dialog in
  `src/TripsView.vue`.
- **Settings panels must load BOTH script and style.** In an `ISettings::getForm()`
  call **`Util::addScript('travelmanager', 'travelmanager-<entry>')` AND
  `Util::addStyle('travelmanager', 'travelmanager-<entry>')`**. The
  `@nextcloud/vite-config` build emits a separate `css/travelmanager-<entry>.css`
  that `@import`s the real style chunks; without `addStyle` the panel renders
  completely unstyled (dark inputs, detached controls). The main app page does
  this in `templates/index.php`; the settings panels must do it too.
- **Frontend tests** live in `tests/frontend/**.spec.ts` (Vitest, `happy-dom`),
  run via `npm run test:frontend` / `make test`. Keep testable logic in plain
  `.ts` modules (e.g. `src/bookings.ts`) and use `import type` for cross-module
  types so the test doesn't pull `@nextcloud/*` runtime imports. `vitest.config.ts`
  is intentionally separate from `vite.config.ts`. **`labels.ts` and `store.ts`
  are the deliberate exceptions** — they import `@nextcloud/*` and are therefore
  untested; keep them free of anything worth testing.
- **`package-lock.json` is committed** because the Makefile/CI use `npm ci`
  (which requires it). Run `npm install` (not `npm ci`) when changing deps, then
  commit the updated lockfile.
- **DB identifier length:** keep index names short (`tm_*`) — Nextcloud enforces a
  ~30-char limit (Oracle); table names also stay well under it.
- **IMAP / Horde.** `bytestream/horde-imap-client` is a **runtime** dep (in
  `require`, not `require-dev`) so it ships in the `--no-dev` prod build. It is
  **untyped**, so `HordeImapClient` carries blanket `@psalm-suppress Mixed*`
  (adapter-over-untyped-lib) — keep all parsing/decoding logic that *can* be
  typed (e.g. `Imap/Html`) out of that class so it stays analysable and testable.
  Read-only discipline (V6) is enforced two ways: open the mailbox
  `OPEN_READONLY` (EXAMINE) **and** fetch body parts with `['peek' => true]` so
  the `\Seen` flag is never set. Dedup key is the envelope `message_id` (with a
  synthetic `<tm-{uidvalidity}-{uid}@…>` fallback when absent).
- **Fetch the last N by sequence number, not SEARCH/SORT.** `fetchRecent` reads
  the mailbox `MESSAGES` count and fetches the trailing sequence range
  `max-N+1:max` (new mail arrives last). The earlier approach (`search()` with an empty `Search_Query` + a `SORT
  (REVERSE ARRIVAL)` option) is fragile: some servers (e.g. Purelymail) reject the
  resulting `UID SORT … ` with no search key as **`UID failed. Illegal
  arguments.`**, and SORT isn't universally supported. Sequence fetch needs only
  base IMAP. Surface real Horde errors via the exception's public `$details`
  (raw server response) — `getMessage()` alone is the generic "IMAP error
  reported by server." (see `HordeImapClient::describe()`).
- **Messages reach the model oldest first, and that is a contract.** *Which*
  messages (the newest N) and *what order* they are handed over in are separate
  decisions: `fetchRecent` sorts the window ascending **by UID**, and
  `IngestionService` must not re-order it. Deduplication treats the first email
  about a booking as the one that creates it and every later one as being *about*
  that booking, so scheduling newest-first made "first" mean "whichever
  extraction task happened to finish first" — which is arbitrary, and put the
  duplicate flag on whichever of two emails won the race rather than on the later
  one. Sort **by UID, not by the order the server sent the untagged FETCH
  responses in**: UIDs increase strictly with arrival within a UIDVALIDITY
  (RFC 3501), so that is arrival order by definition rather than by convention.
  Deliberately **not** by the `Date` header — on forwarded mail it is the
  forwarding time, so it carries no more truth than arrival order and is
  client-controlled besides. **This orders scheduling only**: extraction is
  asynchronous, so with more than one AI worker the results can still arrive out
  of order. A hard guarantee would mean serialising per user or holding results
  until earlier ones are applied, both of which fight the async design; not done.
- **Loading the bundled Composer autoloader.** Nextcloud only auto-includes an
  app's **`<app>/composer/autoload.php`** (`OC_App::registerAutoloading`), **not
  `<app>/vendor/autoload.php`**. Our build ships the autoloader under `vendor/`,
  so Horde's PSR-0 classes (`Horde_Imap_Client_Socket`, non-`OCA\` namespace, thus
  invisible to NC's own loader) are *not registered* on a web request even though
  the files are on disk — you get `Class "Horde_Imap_Client_Socket" not found`
  from `HordeImapClient` while `php -r "require '<app>/vendor/autoload.php'; …"`
  succeeds (that CLI test only proves the files exist, not that NC loaded them).
  Fix: `Application::__construct()` `require_once`s `__DIR__/../../vendor/autoload.php`
  itself (idempotent via Composer's static guard). Note the `-o` (not `-a`) build
  keeps PSR-0 filesystem fallback, so this is purely a *registration* problem, not
  a stale-classmap/opcache one.
- **Security:** the original brief contained a live **OpenRouter API key** — it is
  **exposed/compromised, must be rotated, and is not used anywhere** (the app
  routes through Nextcloud Task Processing, never a hard-coded provider). Never add
  provider keys to this app.

## 8. Conventions

OCP interfaces only (no `OC\` internals); constructor DI; DB via query
builder + migrations; secrets via `ICredentialsManager`; feature-flag the whole
pipeline (global `IAppConfig` flag + per-user enabled flag). Mock the LLM and
IMAP boundaries in tests.
