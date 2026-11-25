# Decisions

Settled — build to these. Each entry says what was decided and why; the
one-line form of each is in `CLAUDE.md`. Add new entries here, dated.

**Contents:**

- LLM provider is admin-global
- The model is shown, never chosen
- IMAP is strictly read-only
- Times are local wall-clock
- Trips are first-class in MVP
- Draft-then-confirm
- One message = one booking (MVP)
- Whether two emails describe one booking is a judgement, not a key
- Train and bus are the flight shape, not a new one
- Booking state is two orthogonal axes, never one column
- Failed extractions are retried per message, not by wiping
- A message has two axes too: what the pipeline saw, and what the user decided
- Model load is paced by the app, not by how much mail is read
- Backup and restore is one user's data in one versioned JSON file
- PDF attachments are read, with prinsfrank/pdfparser plus a column marker
- A mailbox connection is verified, and goes only where it was checked
- Background jobs run in system context
- No password confirmation, anywhere — by decision

These resolve the brief's V1–V10. Several **override** the original brief; the
code follows the overrides.

- **LLM provider is admin-global** (Task Processing). There is **no** per-user
  local/external toggle and **no** direct strategy in the MVP — that was dropped
  (V2/V9). `ILlmService` remains a thin seam for a future JSON-mode backend.
- **The model is shown, never chosen** (2026-09-14). Both settings panels carry
  a read-only **Extraction model** section: an `NcNoteCard` carrying both the
  privacy notice (email content goes to a model) and the limit of what the panel
  claims — it moved off the personal panel's mailbox section, where the first
  half sat next to IMAP settings that have nothing to do with the model — then a
  label/value block (provider name + id, model, endpoint URL, max output tokens,
  task type, the provider's own runtime estimate), and the full extraction
  prompt in a **collapsed `<details>`** with the Messages view's floating Copy
  button — same markup and CSS as the diagnostic sections there, and collapsed
  for the same reason: several thousand characters you consult occasionally must
  not push the rest off the screen.
  The pairs are `<span>`s in a grid mirroring `.tm-fields`, **not a `<dl>`** —
  the server styles bare semantic elements hard (nextcloud `vue-frontend` skill) and something in that stack
  put ~55px of leading on every row. Four things make it what it is:
  - **It describes configuration, not provenance.** Task Processing records no
    provider or model against a completed task — `OCP\TaskProcessing\Task` has a
    getter for neither — so the only honest claim available is what the *next*
    extraction will use, and an `NcNoteCard` says exactly that. Per-row truth
    would mean snapshotting the description at schedule time; deliberately not
    done yet, since the activity log already records the prompt per run.
  - **The model comes from OCP; the URL cannot.**
    `IProvider::getOptionalInputShapeDefaults()` reports `model` and
    `max_tokens`, and because the app never passes a `model` input itself, the
    default a provider names is literally what runs. **No interface reports a
    URL** — Task Processing hides the transport on purpose — so
    `ENDPOINT_KEYS` maps app id → config key (`integration_openai` ⇒ `url`) and
    reads the provider app's own admin config, keyed on the segment before the
    first `-` in the provider id. That makes those key names someone else's
    private contract: an unknown provider, a renamed key or a local model with
    no endpoint reports **nothing rather than a guess**, and a key the owning
    app marks **sensitive is never read at all**, because the API key lives in
    that same namespace under a name we do not control.
  - **The endpoint is admin-only; the rest is not.** Which model is answering is
    worth knowing to anyone debugging their own extractions, but the URL is
    admin-configured infrastructure and may name an internal host, so
    `PersonalSettings` passes `toArray($isAdmin)` and the panel *says* the row
    is admin-only rather than leaving a blank that reads like a bug.
  - **Delivered as initial state, not an OCS endpoint.** It is a property of the
    instance's configuration, not something the page can change or poll, and it
    keeps the OpenAPI surface (and its docblock tax) untouched.

  `ExtractionService::promptTemplate()` is the rules + schema on their own, split
  out of `buildPrompt` — which is now that template plus the email, unchanged
  byte for byte. The alternative was rendering the template against an invented
  email, which puts words on a debugging screen that were never sent.
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

  **A booking is never matched against one written earlier in the same
  `applyExtraction` call** (2026-11-10). The model has just said they are
  separate bookings, and a Flibco email carrying an outward and a return had
  the return matched against the outward created a moment before — the email
  reported as *related to itself*. A duplicate the model returns twice is a draft to discard; a
  suppressed booking is gone. `findCandidates` excludes the call's own ids.

  The rules, in order (`BookingMatcher::match`):
  1. **A shared identifier is decisive**, without consulting provider or dates —
     a rebooking keeps its reference and changes everything else. Identifiers are
     normalised (uppercase, alphanumerics only), floored at 5 characters and
     filtered against a placeholder stoplist. Two exceptions drop it to rule 3:
     a *short all-numeric* shared identifier must be corroborated by a provider
     or a date; and when **each side also carries an identifier the other
     lacks**, sharing one is not enough (2026-11-10). That second one is what
     two bookings in one order look like — a Flibco outward and return shared
     the order number 27874980 and had their own tickets, 37713299 and
     37713300, and the return was suppressed as a second email about the
     outward. A follow-up about the *same* booking repeats what it shares and
     adds nothing of its own (the hotel case above), so it still matches.
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
     `BookingMatch::REASON_POSSIBLE` and the booking is written **into the
     candidate's duplicate group** — see the next decision for where that then
     shows up. The matcher still returns at most one candidate; joining that
     one's group is what makes a third booking land with the other two.

  **Dates corroborate; they never veto**, and they are compared by **calendar
  day** — a confirmation gives a 14:00 check-in and the reminder a bare date,
  which `normalizeDate` renders as 00:00. Local wall-clock throughout (V8), by
  comparing the stored strings, never timestamps. `BookingMapper::findMatchCandidates`
  only fetches a **superset** (same type, within ±14 days, or an identifier hit
  so a rebooking outside the window is still seen); it must never encode the
  decision. **Identifier hits are found in PHP, not SQL** (2026-11-12, review
  item 14): the columns hold identifiers as the model wrote them, so an `IN`
  never matched a stored `ES867-772 590` against an incoming `es867772590`, and
  outside the window that rebooking became a second draft. `findIdentifiers`
  returns both identifier columns of the user's bookings of that type,
  `BookingService::findCandidates` normalises them with the matcher's own
  `identifierSet`, and the hits go to `findMatchCandidates` as ids. Storing a
  normalised column was rejected: a migration and backfill, and the matcher's
  rule copied into the schema, to save scanning a few hundred rows. The prompt was tightened in the same change — `provider`
  is defined as the operator that delivers the service, the agency belongs in the
  per-type detail field, and a lone identifier goes in `booking_reference` — but
  the matcher must keep tolerating both readings regardless: the wording only
  lowers the rate, and there is no backfill of existing rows.
- **Train and bus are the flight shape, not a new one** (2026-09-06). Both are a
  sequence of timed hops between places, so they share `segments[]` +
  `passengers[]`, one validator (`validateSegmentedDetails`) and one calendar
  treatment (`legItems`). They are **two types, not one "ground transport"**:
  they differ to anyone reading a grid or a calendar bar — own icon, own label,
  own filter chip — while the shared shape means the code is written once anyway.
  Two real emails (an SNCB-NMBS International rail confirmation and an Easybook
  coach order summary) drove the details, and each left a rule behind:
  - **`details.retailer` holds the seller**, exactly as `car_rental.supplier`
    does, and `providerNames()` pulls it into the set. The Easybook email names
    both roles plainly (Easybook sells, Perdana Express drives); the SNCB one
    names its operators **only inside image filenames** (`carrier/ecd.png`), so
    after HTML→text the sole company name in the body is the seller. Hence the
    one concession the prompt makes: when the email never names the operating
    company, the seller may stand in `provider` as well. A null Provider column
    on every rail booking is worse than an imprecise one, and the matcher is
    unaffected either way because it compares the set.
  - **An identifier must name the booking, and that is now stated by class.**
    The SNCB email carries `customer number: 42875099` — eight digits, so
    `isStrong()` calls it decisive — and it is *the same in every email that
    sender ever writes*: had it reached `confirmation_number`, the user's second
    SNCB booking would have been silently suppressed as a duplicate of the first.
    The Easybook email carries nine identifier-shaped values including a service
    number and a second, contradictory `Ticket ID` pair from the invoice block.
    So the prompt rules out customer/account, loyalty, VAT, invoice-line and
    route/trip/service numbers **as classes**, rather than listing examples.
  - **A service number is not an identifier, it is `segments[].serviceNumber`** —
    the counterpart of `flightNumber`, and it does the same work: `sameService()`
    (was `sameFlight`) guards rule 2 for all of `SCHEDULED_TYPES`, because "same
    operator, same day" is weak for an airline and weaker still for a rail or
    coach operator running a route hourly.
  - **A seat belongs to a passenger *and* a leg, so it is a list on the leg**
    (`segments[].seats[] = {passenger, coach, seat}`). This was first shipped as
    the flight scalar `segments[].seat` and corrected the same day, by a Eurostar
    confirmation that states the whole matrix: two travellers over an outbound and
    a return is **four** assignments (coach 8/seats 18 and 17 out, coach 16/seats
    54 and 53 back), and the scalar kept one per leg — the extraction silently
    dropped the second passenger from both. Moving the field to the *passenger*
    would have been the same mistake mirrored, dropping the leg axis instead;
    only the per-leg list holds both. The scalar `coach`/`seat` are still read as
    a fallback (`seatLines` in `src/bookings.ts`), since rows extracted before
    this carry them and re-running a message is the user's choice, not ours.
    Flights keep the scalar: an itinerary for two rarely differs by traveller and
    no flight email observed states a per-passenger seat.
  - **`SegmentField.value` may be a list**, which renders one line per entry. It
    exists for seats: a leg has one per passenger, and joining them into a
    sentence puts a table inside a string.
  - `passengers[].ticketNumber` was added — to flights too, since a PNR holds
    e-ticket numbers the same way — so the per-passenger identifiers survive even
    though nothing compares them yet.
  - **Identifier comparison stays on the two header columns.** Adding
    `details`-level identifiers to `identifierSet()` was considered and
    deliberately deferred: it is a change to the most consequential unit in the
    app, on speculation, and as the existing schemas stand it would be a no-op
    for flight/accommodation/car_rental anyway. Storing the ticket numbers is
    what keeps that door open — if a real miss shows up, the data is already there.
  - **Identifiers are chosen by role, not by order of appearance** (added after a
    second Easybook email put the order code in `booking_reference` and dropped
    the boarding code entirely): `booking_reference` is what the traveller
    presents in order to travel — boarding code, PNR, collection code —
    and `confirmation_number` is what identifies the order with the seller. With
    nine identifier-shaped values in one email, "include both when the email
    shows both" was not guidance enough; the matcher is unaffected either way
    (it compares the set) but the card showed the least useful of the nine.
  - **A seat printed under a passenger still belongs to the leg.** The same email
    dropped both seats: it prints them inside each passenger's block, and since a
    passenger has no seat field the model discarded them rather than moving them
    to the segment. The prompt now says so outright, and `passengerLines` renders
    an off-schema `passengers[].seat` if one ever arrives — a seat in an odd place
    beats a seat that silently vanishes. That email also contradicts itself (a
    summary line pairs the two seats with the two ticket ids in one order, the
    per-passenger blocks in the other), so the prompt names the per-passenger
    block as authoritative.
  - **Personal attributes are never extracted.** The coach email states each
    traveller's nationality, gender and mobile number. `validateDetails` passes
    unknown fields straight through to the database, so the prompt is the only
    gate: names only, and the rule is tested.

- **A possible duplicate is a relation between bookings, not a fact about an
  email.** It lives in `bookings.duplicate_group_id`. It was briefly in
  `messages.related_booking_ids`, which was wrong twice over: it could only be
  shown in the Messages view, while the thing to act on is a pair of *bookings*;
  and it made that column mean two things at once — "every booking in this email
  already existed" (the `related` status, genuinely message-level) and "this
  email made a booking that resembles another" — so no one label could be right
  for both. `related_booking_ids` now means only the first, and
  `AppliedExtraction::relatedBookingIds()` filters to **suppressed matches only**.
  Consequences:
  - **A group, not a directed edge.** "Is a duplicate of" is an equivalence, and
    three emails about one booking is ordinary. The first cut stored
    `possible_duplicate_of` pointing at the matched candidate; since candidates
    come back oldest-first that made a **star**, which worked for a pair and
    failed quietly for three: the hub saw both spokes and neither spoke saw the
    other, and dismissing from the hub dissolved a relationship between two
    bookings nobody had adjudicated. With a group id every member sees every
    other (`possibleDuplicates` in `src/bookings.ts` is now just "same group, not
    me"), and one member leaving disturbs nothing else.
  - **Joining a group writes to an existing booking.** The one field an incoming
    email may set on a row it did not create — `duplicateGroupFor` names the
    group after the older booking and stamps it on both, because a flag only one
    side carries is a flag only one side can show. It is app bookkeeping, not
    extracted data: no column the email described is touched, `review_state`
    least of all.
  - **A group of one is dissolved** (`tidyDuplicateGroup`, on leave and on
    purge). It claims nothing, and left behind it reads like a flag in the
    database while showing as nothing in the UI. The tidy runs inside the
    transaction that removed the member, so no crash separates them, **and
    again after the commit**, because inside it two members leaving at once
    each still see the other. The clear names the one booking left, never the
    group, so one that joined meanwhile keeps its flag.
  - **Discarded and archived hide the flag rather than clear it.** You have
    already decided about that booking, so there is nothing left to check — but
    restoring it brings the flag back, which is what a soft state is for. Hiding
    is a filter in `possibleDuplicates`, not a write.
  - **"Not a duplicate" is the permanent answer, and it acts on a row** (`DELETE
    /api/bookings/{id}/duplicate` → `BookingService::leaveDuplicateGroup`, where
    `{id}` is the booking *listed in the row*, not the card you are on). With
    three bookings grouped, "not a duplicate" has to name which one, and the row
    is the only place that answer is unambiguous; the rest stay grouped.
    Deliberately *not* a review transition: the two state axes stay orthogonal,
    and this is neither a fact about the booking nor a decision about keeping it.
    Without it the flag would be an accusation the user can never answer, which
    trains people to ignore the section.
  - **The answer lives in the "Potential duplicates" section, not in Actions.**
    Actions is the booking's lifecycle (confirm / archive / discard / file under
    a trip); a relation-level control sitting there read as a fourth way of
    saying "keep this", indistinguishable from **Confirm** to anyone not holding
    the data model in their head. They genuinely are different — confirming one
    of two duplicates does not answer whether they are the same, and you may
    confirm both and decide afterwards — which is why the fix is placement
    rather than merging them.
  - **Surfaced on the lists, but not on the calendar's bars.** The flag creates
    a task, so it needs somewhere to be *found*, not just somewhere to be read:
    - **Bookings grid** — a `ContentDuplicate` icon beside the title, plus a
      "Possible duplicates" filter chip (`filterByReviewState`, which is where
      that chip row already lives even though this is not a review state — the
      chips answer one question, "which bookings am I looking at"). The mark sits
      on the *identity*, never in the Status column: status is what the email
      said, review state is what you decided, and "there may be two of these" is
      a third, orthogonal thing. It is beside the title button rather than inside
      it, because the title truncates with an ellipsis and an icon within it
      would be clipped first on exactly the long titles where it matters.
      `ContentDuplicate` in `--color-warning`, deliberately **not** a warning
      triangle: this is a question, often a benign one, and alert iconography
      here spends the alarm the Messages notices need for real failures.
    - **Calendar — nothing on the bars, a count in the month summary.** A bar
      already spends its colour on which trip, its outline on draft, and its one
      glyph on the booking type, which below 640px is *all* that survives the
      container query. There is no room for a second cue and no cue to give up.
      A flagged pair also already draws two bars over the same days, which is the
      signal. So it goes where this month's outstanding work already lives: a
      `{n} possible duplicate(s)` button beside `{n} draft(s) to review`, opening
      the earliest (`firstDuplicate`, extracted alongside `firstDraft` over a
      shared `earliest`). `CalendarItem.duplicate` is passed in rather than read
      off the booking, since the rule involves every *other* booking.
    - **`hasPossibleDuplicate` delegates to `possibleDuplicates`** rather than
      testing `duplicateGroupId`: a group whose only other member you discarded
      still leaves an id on the row, and a mark with nothing left to compare
      against is a task the user cannot finish. It is asked against the **whole**
      booking list, never a filtered one — a partner hidden by the calendar's
      archived toggle is still one you have to settle, and dropping the mark when
      you hide it would make the toggle look like it resolved something.
    - **No navigation counter.** The month summary and the filter chip cover it;
      the nav is the one place a number costs attention on every screen.
  - **The Messages row banner shows on every email of a group**, derived from
    that run's booking having a partner left to compare with
    (`messageNotices(message, created, allBookings)` → `hasPossibleDuplicate`,
    the Bookings grid's rule), not from the message's own columns. It used to
    test the group id alone, so it outlived the comparison: once the other
    booking was discarded or archived the id stayed, the sidebar listed nobody,
    and the banner asked for a task that could no longer be done (2026-11-13,
    review item 18). It was briefly on the last email only —
    under the edge model that was the one booking carrying the edge — but group
    membership is symmetric, and which email arrived second is an accident of
    order the reader cannot see. The wording says a booking *may be the same as*
    one from another email, so it is true read from either side.
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
    is one click from being filed. **That only works if the screen knows** (2026-11-12,
    review item 6): a failed submit reloads whenever something before the
    failure was written, says when the confirm itself went through, and
    remembers what it did — a created trip becomes the choice and a done
    confirm is skipped — so pressing it again neither creates the trip twice
    nor re-confirms.
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
- **A message has two axes too: what the pipeline saw, and what the user decided**
  (2026-09-17, app version 1.17.0). `messages.status` stays the pipeline's and is
  written only by it; **`messages.discarded`** is the user's and is written only
  by an explicit action (`POST`/`DELETE /api/messages/{id}/discard` →
  `IngestionService::discardMessage`). The attention counter is now
  `unresolved(m) && !m.discarded`.
  **Why:** `failed`/`dropped` is a good bucket for "the app got no booking out of
  an email that looked like it had one", but `dropped` covers both *the model got
  it wrong* and *the email genuinely carries nothing a booking needs* — a real
  itinerary email with a reference, an airline and no departure time is refused
  correctly, and refused again on every retry. Such a row sat in the counter
  permanently, which is how a counter gets ignored. Retry answers the first case;
  this answers the second. Consequences:
  - **Never flattened into `status`.** A discarded row still has to say *why*
    nothing was extracted — that text is what prompt tuning reads — so the
    diagnosis and the decision cannot share a column. Same reasoning as
    `bookings.status` vs `review_state`, and the same mistake avoided.
  - **Not a delete**, and softer than a booking's discard in exactly one way:
    the row is the dedup key, so removing it would have the next mailbox read
    ingest the same email into the same dead end. It is also what a retry needs
    if the prompt improves later. The toast and the row's notice both say so, so
    "keep a copy, stop asking" reads as deliberate rather than half-finished.
  - **Discard / Restore, not a word of its own.** A booking already uses both
    for this idea and discarding is soft there too, so the vocabulary is learned
    once. **Archive was considered and rejected**: on a booking it means the
    travel happened and is done with, and a dead-end extraction was never
    completed work — overloading it would have made both weaker. `Restore` is
    reused without that worry, since it means only "the way back".
  - **Both axes show, as on the Bookings grid**: the status badge keeps its
    wording (the diagnosis, and what prompt tuning reads) and a second
    **Discarded** lozenge carries the decision. The amber leaves with it — amber
    means "this wants something from you" — and the row mutes (`tm-muted`, the
    class discarded bookings already use) rather than hiding, because the ledger
    records what happened.
  - **`unresolved()` is deliberately not `needsAttention()`.** It tests the
    status alone, so it stays true after a discard — otherwise the button that
    undoes the action would disappear along with the action. It is also what
    keeps the button off rows that never asked for anything.
  - **Discarded rows stay in every other filter**, including their own status,
    plus a **Discarded** chip so one can be found again. Discarding changes what
    the app asks of you, not what it did.
  - **A retry clears it**, because re-running is how you say you think it can work
    after all — and that attempt's outcome is unknown, so a failure the user has
    not seen yet must be able to ask again.
- **Model load is paced by the app, not by how much mail is read** (2026-09-30).
  Reading the mailbox and sending to the model are **separate steps**: ingestion
  stores new mail as `messages.status = queued`, and `ExtractionQueue` releases
  it under two admin settings. Before this, fetching *was* scheduling —
  `rate_limit_per_run` was simultaneously "how far back we look" and "how many
  tasks we start", and those pull in opposite directions (a backfill wants the
  first large, load protection wants the second small). Consequences:
  - **Two limits, because they protect different things.** `max_in_flight`
    (default 2) bounds how many of our tasks wait in Task Processing at once —
    that is what stops a backlog flooding the queue every other app shares, and
    what bounds a local model. `max_per_hour` (default 60, 0 = off) bounds how
    many we *start*. **A cap never slows anything down**: each completion frees a
    slot and the next task goes at once, so the rate is whatever provider latency
    makes it. The 429s of 2026-09-18 happened under the old cap with cron running
    tasks one at a time — a *rate* problem, which only a rate limit addresses.
    The budget is a rolling count over `tasks.created_at`, so it needs no counter
    of its own and survives restarts; retries count, because they are requests.
  - **Instance-wide, not per user.** The provider and the shared queue are
    global, so per-user caps would multiply with enrolment. `QueuePlanner::pick`
    serves users **in turns**, longest-waiting first, so one user's backfill
    cannot starve another's new mail. Within a user it is strictly oldest first
    (by row id), which keeps the "oldest first" contract in `.claude/rules/imap.md`.
  - **No clock of its own.** The queue runs on the dispatcher tick, after every
    task completion (the listeners), and on a user's Read-mailbox-now or Retry.
    Completions keep a backlog moving; the tick is what resumes it once an hourly
    budget frees up. It **never sleeps** for a slot — that is exactly how
    `integration_openai` handles a 429, and it blocks every other app's AI tasks
    for the duration. Hence also **per hour, not per minute**: with cron-granular
    wake-ups a per-minute rate is not something this code could honour.
  - **Lost tasks are reconciled, or the cap wedges.** Uncapped, a task that never
    reports back strands one row; capped, it holds a slot for ever and enough of
    them stop the queue with nothing on screen saying why. Each tick,
    `reconcile()` asks Task Processing (`ILlmService::findTask`) about pending
    tasks older than 10 minutes: still scheduled/running is left alone however
    old (a busy queue is not ours to cut short); ended with no event handled for
    10 minutes is settled through the ordinary handlers; unknown to the platform
    is `handleLost` → `failed`/`provider`, which is retryable. **"Could not ask"
    is never "gone"** — `findTask` throws on anything but not-found. An older
    pending attempt for a message that has since been retried is marked
    `superseded` and never allowed to overwrite the newer one. **A task is
    settled before anything is written** (2026-11-12, review item 9): the
    listener and the reconciler can arrive at one task, cron and the AI worker
    at the same moment, so each handler claims it with
    `TaskMapMapper::settle`, a conditional update out of `pending`, and only
    the caller that gets the row writes. Checking the loaded status was no
    guard, and settling *last* meant a throw after the bookings committed left
    the task pending, to be applied again as "related" to itself. A crash
    after settling leaves the message `processing` with no pending task, which
    the orphan pass fails as retryable.
  - **Races are tolerated, not prevented.** `claimQueued` is a conditional
    update, so a message is never sent twice. The limits are guarded by a lock in
    the locking cache; with no locking cache configured that lock is NullCache and
    always succeeds, and concurrent pumps can overshoot the cap by a few. Fine for
    load protection; nothing treats the cap as an invariant.
  - **No provider means wait, not fail.** `pump()` checks `hasProvider()` before
    claiming anything, so an unconfigured provider leaves mail queued instead of
    turning each email into a failure to retry by hand.
  - **The feature flag gates automatic work only.** The dispatcher and the
    listeners check it; Read mailbox now and Retry do not, as before — a user
    asking explicitly has always worked with the pipeline off.
  - **Retry goes through the queue** and is refused (400) while a message is
    `queued` or `processing`: a second attempt in flight for one email means
    whichever answer lands last wins. The requeue is one conditional update
    from the status the request read (`requeueForRetry`), so two clicks that
    both read `failed` cannot both get through.
  - **`local_concurrency` is gone.** It promised "max concurrent local-model
    extractions", was read by nothing, and could not have been honoured by any app
    — how many tasks *run* at once is the server's AI worker count. The admin
    panel's helper texts now say what each setting cannot do, too. The stale row
    is left in `oc_appconfig`; deleting it would take a migration for nothing.
  - **UI:** `queued` is labelled *Queued*, shares the *Waiting* filter chip with
    `processing` (from the user's side both mean "not answered yet"), never counts
    as needing attention, and carries a notice saying *why* it waits — a queued
    row with no reason reads as stuck. The activity log says which limit is
    holding work back.
  - **Still open: the read window.** `fetchRecent` still reads only the newest
    `rate_limit_per_run` messages by sequence number, with no cursor — anything
    older on first enrolment, or any burst larger than that between runs, is
    never seen. `messages.imap_uid`/`uid_validity` are stored and unread; a UID
    high-water mark is the planned fix, and this queue is what makes reading
    everything safe.
- **Backup and restore is one user's data in one versioned JSON file** (2026-11-05).
  Personal settings → *Backup & restore*, `GET /api/backup/export`, `POST
  /api/backup/preview` (writes nothing) and `POST /api/backup/import`. Importing
  deliberately asks for **no password**, even with "replace": the preview dialog with
  its counts is the confirmation (see "No password confirmation" below).
  Consequences:
  - **JSON, not SQLite.** SQLite would need `pdo_sqlite` on a MySQL or Postgres
    server, and would mean opening a user-uploaded database file. `BackupFormat::VERSION`
    guards the shape: a newer file is refused with a request to update the app.
  - **What is in it:** trips, bookings and the whole message ledger, whose RFC
    Message-IDs are what make the same mailbox read as already processed afterwards.
    **What is not:** settings (the IMAP password above all), the debug log, `tasks`
    (they point at tasks on the exporting server) and `user_id`, so a backup can move
    between accounts. Ids stay in the file only so its links can be followed, and are
    rewritten on the way in (`trip_id`, `duplicate_group_id`, `related_booking_ids`),
    so a `#/bookings/42` link does not survive a restore.
  - **Export goes through `findAllOldestFirst`, never the list view's
    `findAllForUser`**, which caps at 200 rows and would forget the rest of the dedup
    record. `testAnExportCoversEveryColumn` fails if a new entity column is left out.
  - **Email text is an opt-out** (`bodyText` + `lastResponse`): a smaller, less
    sensitive file, whose messages can never be retried. Subjects, senders and `error`
    stay. The file is not encrypted, and the panel says so.
  - **Replace is all or nothing.** The file is validated in full first, then the
    deletes and every insert share one transaction. It deletes **trips** too, unlike
    the developer wipe, because the user asked for the backup's state rather than a
    reprocess. The activity log survives and records the import.
  - **A row waiting for the model cannot stay that way.** Its task belongs to the
    exporting server, so `queued`/`processing` both come back as `queued`, and the
    next dispatcher tick sends them. The import itself never calls the model. If the
    backup left the text out, such a row is **not imported at all**: written, it would
    mark the email as handled with nothing to extract from, while left out it is read
    afresh if it is still in the mailbox. The preview says how many will be sent.
  - **Into existing data, the row already there always wins**, and nothing is
    overwritten. Rows from the file are compared only with rows from before the import,
    never with each other, so an empty database restores the file exactly:
    - **Messages** match on RFC Message-ID.
    - **Bookings** match on **same source email and same type**, one existing booking
      per row of the file. This is deliberately **not** `BookingMatcher`: an import is
      not new evidence about a booking, and a restore should not make judgement calls.
      The type is what stops a flight standing in for the hotel booked in the same
      email. A booking deleted here does come back, since deletion leaves no tombstone;
      the same booking from a *different* email is imported, unflagged.
    - **Trips** match by case-insensitive name, since nothing else identifies one; the
      oldest namesake takes the imported bookings. Two different trips called "Paris"
      merge. That is the price of importing a file twice staying clean.
    - **Duplicate groups** span the rows written plus the existing rows that stood in
      for the rest, so a group is not lost because one member was skipped. A group left
      with one member is dropped. Stamping an existing row is the one write to it, and
      it is bookkeeping as in `duplicateGroupFor`.
  - **Everything skipped is listed**, with a link to the row it gave way to, in the
    dialog after an import and in the activity log. The links open a **new tab**,
    because the list lives only in that dialog and following one in place would lose
    it; **Download import report** saves the same list as text (`importReportText`).
    `messages.status` is left alone
    when its booking is skipped: `source_message_id` already links the two.
  - **A file can make us store no more than the app would have written**
    (2026-11-12, review items 8 and 24). It is text anyone can edit, and a `queued`
    row goes to the shared model on the next tick. So `BackupFormat` cuts a waiting
    email's text to `MessageText::TOTAL` bytes exactly as ingestion would, a
    finished one to TOTAL *characters* (bodies before 1.19.0 were capped at 20 000
    characters, more bytes than TOTAL in a non-Latin script), and `error`,
    `lastResponse` and trip notes to 20 000 characters. What cannot be cut refuses
    the file: `details` over 100 kB, more than 50 000 rows in a list, an
    `attempts` beyond INTEGER, and a list whose **comma-joined form** outgrows its
    `VARCHAR(255)`. Every string loses NUL bytes (valid JSON, refused by
    PostgreSQL). Each of these previewed cleanly and then failed the import.
    `related_booking_ids` is fitted once more on insert, because this server's ids
    can be longer than the file's.
  - **The file is memory-bound and upload-bound.** `json_decode` reads it whole, and
    PHP's `post_max_size` drops an oversized body without an error, which is why "no
    file received" names the upload limit as a likely cause.
- **PDF attachments are read, with prinsfrank/pdfparser plus a column marker**
  (2026-11-10, app version 1.19.0; full spec and measurements in
  `docs/pdf-attachments-spec.md`). Some booking emails say only "your ticket is
  attached". Their PDFs' text now goes to the model inside `body_text`, under a
  `--- ATTACHMENT: <name> (PDF, N pages) ---` heading each, so `body_text` stays
  exactly what the model was given and Retry, the Raw message pane and backups
  needed no change. Three real tickets (FlixBus, Flibco, a Malaysia Airlines
  Amadeus e-ticket) were run through every candidate and the configured model.
  What was ruled out, so the question is not reopened:
  - **`pdftotext` (poppler)** extracts best, and cannot be relied on: the
    Nextcloud snap ships without it and nothing can be installed into a snap.
  - **PDF Viewer** is pdf.js in the browser; its PHP side is a settings page.
    No Nextcloud app offers server-side PDF text to other apps. The **Assistant**
    reads PDFs with `smalot/pdfparser`'s `getText()`.
  - **smalot** estimates glyph widths and spaces a gap only at **2 em or more**,
    hard-coded. The Amadeus ticket draws every word separately with ~0.35 em
    gaps and no space glyphs, so it came out `MH120406:40` and the model read
    flight **MH120 at the wrong times** — which passed validation silently.
  - **prinsfrank** computes real advance widths (bundling the 14 standard
    fonts' metrics) and breaks words at 0.25 em; words came out right on all
    three. Its one weakness is joining side-by-side columns with a single space,
    which swapped a boarding pass's luggage weights and merged a shuttle's
    origin address into its destination. `PdfLayout::separator` puts a **tab** at
    gaps over **0.8 em** (measured: text ≤ 0.43 em, columns ≥ 1.14 em), and with
    it all three extracted correctly. The prompt says tabs are column breaks.
  Consequences:
  - **Downloaded after dedup, not in `fetchRecent`**, which lists PDFs from the
    structure (`ImapMessage::$attachments`) and leaves the bytes to
    `IImapClient::fetchParts` — otherwise every run re-downloads the newest N
    messages' files.
  - **Caps:** 3 PDFs, 5 MB decoded, 10 pages and 8 000 bytes of text each;
    `body_text` 32 000 in total, the body taking what is left and never more than
    its old 20 000. A cut is marked `[… truncated]`.
  - **A fatal error is guarded by a breadcrumb**, not a catch: the user config
    `pdf_reading` holds the Message-ID from the moment its PDFs are read until
    the message row is written (`AttachmentService::finish`, from
    `IngestionService::enqueue`'s `finally`). Still set on the next run means
    that run died in between, and the PDFs are skipped as `crashed` — otherwise
    the unstored message is fetched and dies again every 15 minutes. It used to
    be cleared as soon as the PDFs were read, which left a fatal while
    assembling or inserting the row unguarded. Not locked: two concurrent reads
    of one mailbox can clear each other's, which costs at most one more crash.
  - **The listed size is already the decoded estimate.** Horde scales a base64
    part's BODYSTRUCTURE size by 3/4 before `setBytes()`, so `AttachmentRef::$bytes`
    is compared with the 5 MB cap as it is — scaling it again let files of up to
    ~6.7 MB be downloaded only to be refused afterwards.
  - **`messages.attachments` records every PDF found, read or not**, because a
    skipped file that leaves no trace makes the email look as though it had
    none. Its own column, not `issue_reasons`: those describe the last attempt
    and a retry clears them. `no_text` is decided on the *assembled* text, so a
    blank body plus a readable PDF is sent and a blank body plus a scan is not.
  - **`pdf_attachments_enabled` (admin, default on)**; the Extraction model
    section says attachment text is sent too. Off still lists the PDFs, as
    `disabled`, which the Messages view words as information, not a fault.
  - **UI:** notices in the expanded Messages row (one for everything read, one
    per file not read), a muted paperclip beside the subject, and a "PDF
    attachments" list in the sidebar's message panel.
  - The prompt also gained two rules the test exposed. A ticket number always
    goes on its passenger, and stays out of the identifier columns *when there
    is also a PNR* — beside one (Amadeus) it became a matching key, while a
    Flibco shuttle ticket has no other reference, so there it is the
    booking_reference too. And a fare-class letter ("Class: Q") is not a cabin.
- **A mailbox connection is verified, and goes only where it was checked**
  (2026-11-11, from `docs/code-review-2026-11-11.md` items 1–2). The host is a
  value any user sets and cron dials it from the server, so it is treated like
  any other user-supplied URL. Consequences:
  - **Certificates are verified, against Nextcloud's CA bundle.** Horde's socket
    client merges `verify_peer => false` *under* the caller's context, so before
    this nothing was verified and the mailbox password went to whoever answered
    the name. `HordeImapClient::sslContext` now passes `verify_peer`,
    `verify_peer_name` and `cafile = ICertificateManager::getAbsoluteBundlePath()`
    — the bundle Nextcloud's own HTTP client uses, so a self-signed server works
    once its CA is imported with `occ security:certificates:import`. The admin
    switch **Verify mail server certificates** (`imap_verify_tls`, default on)
    is the last-resort opt-out, as Mail's `app.mail.verify-tls-peer` is. A
    refused certificate surfaces only as Horde's bare "Error connecting… [0]"
    (the TLS warning is suppressed), so `describe()` adds a hint saying so.
  - **Only a host name or an IP literal is a host** (`ImapEndpoint`). Horde
    recognises a scheme only at offset 0 and drops a zero port, so
    `tcp://127.0.0.1` and `unix:///path` (port 0) were dialled as written.
    Port 1–65535; encryption one of `ssl`/`tls`/`none` — anything else used to
    fall through to cleartext.
  - **Local and private addresses need the admin's `allow_local_remote_servers`**,
    via `IRemoteHostValidator`, Mail's rule for the same thing. **A mailbox on the
    LAN or the same machine stops working until that flag is set**, and the error
    names it. The validator only classifies the string it is given, so the
    resolver checks the name *and* every A/AAAA address it resolves to, and one
    local address refuses the lot.
  - **The checked address is the one dialled.** Resolving again at connect time
    would let a short-lived DNS answer pass as public and connect as 127.0.0.1,
    so `hostspec` is the IP and the name rides in `peer_name` (certificate check
    and SNI). IPv4 is preferred: without Happy Eyeballs a dead IPv6 route is a
    timeout, not a fallback.
  - **Checked on save and again before every connection.** Saving refuses a bad
    address with a 400 and writes nothing, but does only the checks that need no
    DNS — what a name resolves to can change after saving, and a DNS hiccup
    should not stop someone saving their password. Rows saved before these rules
    are still in the database, which is the other reason for the second check.
- **Background jobs run in system context**, acting on behalf of each user: Task
  Processing tasks carry the `userId`; correlation rides on `customId` + the
  `tasks` table.
- Secrets (IMAP app password) only via `ICredentialsManager`, encrypted, keyed by
  uid — never in app/user config, never logged.
- **No password confirmation, anywhere — by decision** (2026-11-12). Saving the
  IMAP password, importing a backup (even with "replace") and the data wipe all
  rely on their own confirm dialog and on the OCS CSRF header
  (`OCS-APIREQUEST`, which a cross-site request cannot set without a preflight).
  `docs/code-review-2026-11-11.md` item 20 recommended adding
  `#[PasswordConfirmationRequired]` to import and wipe; it was briefly done and
  then removed, along with the one on the settings save and the
  `@nextcloud/password-confirmation` dependency. Do not reintroduce it. If it
  ever comes back: a marked method returns **403** outside the ~30-minute sudo
  window, so the frontend must `await confirmPassword()` first, and the package
  must be ^6 (v5 pins Vue 2 / `@nextcloud/vue ^8`).
