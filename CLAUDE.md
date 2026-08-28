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
UI (Vue, OCS API) — three views: Bookings | Trips | Messages
  ├─ Bookings: sortable grid (Title | Trip | Type | Provider | Reference |
  │            Travel dates | Added | Status), rows expand to type-specific fields;
  │            filter by review state + type;
  │            confirm / edit / discard / archive / restore
  ├─ Trips:    sortable grid (Trip | Travel dates | Bookings), dates + type
  │            lozenges derived from the linked bookings; filter All/Current/
  │            Future/Past; rows expand to link/unlink and edit
  └─ Messages: the ingestion ledger as a sortable grid (Subject | From | Date
               received | Last processed | Attempts | Status), rows expand for
               details; filter by status, retry failed extractions
```

Key classes (all under `OCA\TravelManager`, `lib/`):
- `Service/ExtractionService` — **pure, dependency-free** prompt build + JSON
  repair + validation, returning `Dto/ExtractionResult` (kept bookings +
  `Dto/ExtractionIssue[]`). This is the most-tested unit.
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
  + `Version1800Date2026082800…` (adds `messages.related_booking_ids`).
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
- `trips` — user-defined grouping.
- `bookings` — one canonical booking per confirmation; natural key
  `(user_id, type, provider, booking_reference)` drives update/cancel idempotency;
  `trip_id` links to a trip. **Two orthogonal state axes** (see §3):
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
  it. A later email matching the natural key is **reported, never applied**:
  `applyOne` returns a `RelatedBooking` (id + description) instead of writing,
  `applyExtraction` returns an `AppliedExtraction` (created + related), and the
  message lands in `messages.status = related` — named in its notes *and* linked
  by id in `related_booking_ids`, so the UI can open the booking it matched.
  **Why:** an extraction is a full replacement, not a patch — the old code
  overwrote every column including nulls, so a follow-up email that omitted the
  confirmation number erased it, and a change email listing one leg of a two-leg
  flight truncated `details.segments`. Proper update semantics (which fields
  merge, which replace, what a partial email means) is a design question in its
  own right and is deferred until the simple flow is solid. **Consequence to keep in
  mind:** a cancellation email no longer cancels the booking — it is flagged and
  the user acts. `describeRelated` calls that case out by name.
- **Booking state is two orthogonal axes, never one column.** `status` is a fact
  about the booking (`active`/`cancelled`/`superseded`) and is the *only* one the
  extraction writes; `review_state` is the user's decision
  (`draft`/`confirmed`/`discarded`/`archived`) and is *only* ever written by an
  explicit user action. Flattened into one column these were mutually exclusive —
  a cancelled booking could not also be one you had reviewed. Consequences:
  - **Discard and archive are soft.** The row survives as a tombstone, so the user
    can undo *and* so a later email matching the same natural key cannot resurrect
    a discarded booking as a fresh draft (`applyOne` deliberately never touches
    `review_state` on update). Hard deletion is a separate, explicit action
    (`BookingService::purge`, `DELETE /api/bookings/{id}`) — and because it leaves
    no tombstone, a later email *will* re-create the booking.
  - **Archiving is manual** (a user button) for now; an automatic sweep on
    `end_date` + a cooling period is deferred, and is the intended trigger for
    hard-deleting retained email bodies once message-body persistence lands.
  - Review transitions all go through `POST /api/bookings/{id}/review`.
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
    The link is applied **before** the review change, so a failed link leaves the
    booking a draft (press Confirm again) rather than confirmed but orphaned.
    Only `draft → confirmed` opens it: *restoring* a discarded booking also
    targets `confirmed` but is not a first decision, hence the `reviewState`
    check in `onReviewAction`, not a target check alone.
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
- ✅ **Messages grid**: sortable column headings (From | Subject | Date received |
  Last processed | Attempts | Status) over expandable rows; `messages.sender`
  captured from the IMAP envelope (app version 1.6.0).
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
- `BookingDetails.vue` renders a booking's type-specific body for **both** the
  expanded grid row and the sidebar, so the two cannot drift.
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

**UI structure (§2).** `view` selects one of the three views; each view owns its
filter/sort refs. Do not conflate the two again — the original single `filter`
ref made "All bookings" a status and "Trips" a view, which is why adding a
Messages view needed this untangling. All filtering/sorting is **client-side and
pure** (`sortBookings`/`filterBookings` in `src/bookings.ts`,
`sortMessages`/`filterMessagesByStatus` in `src/messages.ts`,
`sortTrips`/`filterTripsByPeriod`/`tripRows` in `src/trips.ts`, shared helpers in
`src/grid.ts`) so it stays unit-testable without `@nextcloud/*` runtime
imports (§7).

**All three views are the same grid**, built from one set of CSS classes
(`.rows`, `.rowSummary`, `.gridHeader`, `.columnHeading`, `.chevron`, `.rowBody`,
`.cellText`/`.cellMeta`/`.cellStatus`) plus a per-view column template
(`.messageColumns` / `.bookingColumns` / `.tripColumns`). Sort behaviour shared
via **`src/grid.ts`** (`SortColumn`, `SortDirection`, `nextSortDirection`,
`formatSpan`, `formatTimestamp`) so no view's module imports another's. Keep them
in step: a change to one grid's look belongs in the shared classes, not copied
into a second set.

It is a CSS grid, not a `<table>`: each row is a native `<details>`, which a
table's two-`<tr>` row pair cannot be without hand-rolling the open/closed state.
Consequences to preserve:
- The heading row and each data row use the **same `.rowSummary` + column-template
  pair and the same cell order**, so one set of `nth-child` rules hides a column
  in both at a breakpoint. The heading's chevron cell is a real (empty) element
  for exactly this reason — do not replace it with an offset. Reordering columns
  means editing three things in step: the `*_COLUMNS` array, the cell order in
  the template, and the `nth-child` breakpoint rules.
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
  have. Sort state is `messageSort` + `messageSortDirection`; `MESSAGE_COLUMNS`
  holds order and each column's default direction, and `nextSortDirection`
  implements "default on a new column, flip on the same one".
- Column **labels live in `App.vue`** as literal `t()` calls (extractable),
  keyed off `MESSAGE_COLUMNS`; `src/messages.ts` stays `@nextcloud/*`-free.
- Rows with no value for the sorted column sink to the bottom in **both**
  directions — unsortable, not "smallest".
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
  `App.vue`.
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
  is intentionally separate from `vite.config.ts`.
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
  `max-N+1:max` (new mail arrives last), then `array_reverse`s to newest-first.
  The earlier approach (`search()` with an empty `Search_Query` + a `SORT
  (REVERSE ARRIVAL)` option) is fragile: some servers (e.g. Purelymail) reject the
  resulting `UID SORT … ` with no search key as **`UID failed. Illegal
  arguments.`**, and SORT isn't universally supported. Sequence fetch needs only
  base IMAP. Surface real Horde errors via the exception's public `$details`
  (raw server response) — `getMessage()` alone is the generic "IMAP error
  reported by server." (see `HordeImapClient::describe()`).
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
