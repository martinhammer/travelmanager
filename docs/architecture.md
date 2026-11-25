# Architecture

Reference for how the app is built. Read the part you need; the
invariants are in `CLAUDE.md`, the reasons in `docs/decisions.md`.

**Contents:** Data flow · Key classes · Data model · Dev/debug tooling ·
Verified NC 33 API facts

## Data flow

```
DispatcherJob (TimedJob, ~15min, no parallel runs)
  ├─ ExtractionQueue.reconcile()  # settle tasks whose events never arrived
  ├─ ExtractionQueue.pump()       # the queue's only clock — see below
  └─ enumerates enrolled users ─→ enqueues UserIngestionJob (QueuedJob) per user
       └─ IngestionService.ingestForUser(uid)
            ├─ IImapClient.fetchRecent()        # READ-ONLY IMAP
            ├─ dedup via ProcessedMessageMapper # by RFC Message-ID
            ├─ AttachmentService.read()         # new mail only: PDF text, see decisions
            └─ store as messages.status = queued, then ExtractionQueue.pump()
ExtractionQueue.pump()   # instance-wide: in-flight cap + hourly budget (QueuePlanner)
  └─ claim queued → processing, oldest first, users taking turns
       └─ ILlmService.scheduleText2Text(prompt, uid, customId=messageId)
            └─ Nextcloud Task Processing (core:text2text), provider = admin-global
TaskSuccessfulEvent / TaskFailedEvent
  ├─ ExtractionResultHandler  (correlates by task_id → TaskMap → user+message)
  │    ├─ ExtractionService.parseAndValidate()  # JSON repair + validation
  │    └─ BookingService.applyExtraction()      # writes DRAFT bookings (+ details JSON)
  └─ ExtractionQueue.pump()   # a finished task frees a slot
UI (Vue, OCS API) — four views: Calendar | Bookings | Trips | Messages
  ├─ Calendar: **the default view**. Month grid, trips and bookings drawn as
  │            bars across the days they cover; ‹ › Today paging, a month-scoped
  │            summary, and a toggle for archived/discarded. Clicking a bar opens
  │            the sidebar *without leaving the calendar*
  ├─ Bookings: sortable grid (Title | Trip | Type | Provider | Reference |
  │            Travel dates | Added | Status); filter by review state + type;
  │            rows do not expand — clicking one opens the detail sidebar. A
  │            ContentDuplicate icon beside a title marks a possible duplicate,
  │            and a "Possible duplicates" filter chip is the queue for them
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

## Key classes

Key classes (all under `OCA\TravelManager`, `lib/`):
- `Service/ExtractionService` — **pure, dependency-free** prompt build + JSON
  repair + validation, returning `Dto/ExtractionResult` (kept bookings +
  `Dto/ExtractionIssue[]`). This is the most-tested unit.
- `Service/BookingMatcher` — **pure, dependency-free** duplicate detection: does
  this extraction describe a booking the user already has? Takes an
  `ExtractedBooking` plus `Dto/MatchCandidate[]` and returns a `Dto/BookingMatch`
  or null. Second-most-tested unit after `ExtractionService`, and for the same
  reason: it is where the judgement calls live.
- `Service/QueuePlanner` — **pure, dependency-free** rules for which queued
  messages go to the model now: the in-flight cap, the hourly budget, and users
  taking turns. `Service/ExtractionQueue` does the I/O around it (claiming,
  scheduling, reconciling lost tasks). See `docs/decisions.md`.
- `Service/IngestionService`, `Service/ExtractionResultHandler`, `Service/BookingService`, `Service/ConfigService`.
- `Llm/ILlmService` → `TaskProcessingLlmService` (single platform strategy);
  `Llm/ProviderInfo` is its read-only description of the model in use.
- `Imap/IImapClient` → `HordeImapClient` (read-only, `Horde_Imap_Client`);
  `Imap/Html` is the pure HTML→text helper for HTML-only bodies;
  `Imap/MimeText` decides which MIME parts make up the text (see `.claude/rules/imap.md`) and lists
  the PDF attachments; `Imap/ImapEndpoint` is the pure rule for what a mailbox
  host/port/encryption may be, and `Imap/ImapEndpointResolver` resolves the host
  and checks every address against `IRemoteHostValidator` (see `docs/decisions.md`);
  `Imap/MessageId` makes the dedup key fit its column without cutting it.
- `Db/ColumnText` — **pure** fitting of outside text to a column (see `.claude/rules/backend.md`).
- `Pdf/IPdfReader` → `PrinsPdfReader` (the only class importing
  `prinsfrank/pdfparser`); `Pdf/PdfLayout` is its pure column-break rule.
  `Service/AttachmentService` reads one email's PDFs under the caps and the
  crash breadcrumb; `Service/MessageText` is the **pure** assembler of
  `body_text` (body + one section per PDF, under the budget). See `docs/decisions.md`.
- `BackgroundJob/DispatcherJob`, `BackgroundJob/UserIngestionJob`.
- `Listener/TaskSuccessfulListener`, `Listener/TaskFailedListener`.
- `Controller/{Booking,Message,Trip,Settings,Admin,Dev,Backup}Controller`; `Settings/*`.
- `Service/BackupFormat` + `Service/BackupImportPlanner` — **pure, dependency-free**
  validation of a backup file and the decision of what to write from it;
  `Service/BackupService` does the I/O. See `docs/decisions.md`.
- `Service/IngestionLogger` (per-user activity log) + `Service/MaintenanceService`
  (per-user data wipe) — see Dev/debug tooling below.

## Data model (5 tables, prefix `travelmanager_`)
- `messages` — IMAP dedup + ingestion audit + **retry source** + **the
  extraction queue**; unique `(user_id, message_id)`; `status` =
  **queued**/processing/processed/failed/no_booking/**dropped**/**related**
  (`queued` = read and stored, not yet sent — see "Model load is paced" in `docs/decisions.md`;
  `processing` = sent, awaiting the model; `related` =
  every booking in it already exists, see `docs/decisions.md`; see the extraction contract
  in `.claude/rules/extraction.md` — `dropped` is the retry-worthy one), `failure_kind` =
  schedule/provider/validation, `issue_reasons` = comma-separated
  `ExtractionIssue::REASON_*` slugs from the last attempt (the branchable form of
  what `error` says in prose; cleared on retry), `related_booking_ids` =
  comma-separated ids of the bookings a `related` email matched but did not touch
  (same rationale; cleared on retry), `attempts` counts extraction runs,
  **`discarded`** is the user's own decision that the row is not worth keeping
  open (see `docs/decisions.md` — cleared on retry, and the only column here a user writes). Holds
  `subject`, `sender` (display form of the From header — grid metadata only,
  never a dedup or classification input; null on rows ingested before it was
  captured, since the envelope is not retained and cannot be backfilled),
  `sent_at` and the plain-text `body_text` that was fed to the model (the body,
  then one `--- ATTACHMENT: … ---` section per PDF read),
  so an extraction can be re-run without going back to IMAP (a message may have
  been deleted, and UIDVALIDITY may have rolled), plus `last_response` — the raw
  model output of the most recent attempt, truncated to 20000 chars, so the
  Messages view can show *what came back* next to the error rather than the error
  alone. `body_text` + `last_response` are the bulky columns: both are meant to be
  dropped once the bookings a message produced are archived. **`attachments`**
  is a JSON list of `Dto/AttachmentRecord` — every PDF found at ingestion and
  its outcome (read / no_text / encrypted / failed / crashed / too_large /
  too_many / disabled); null when the email had none, and untouched by a retry.
- `trips` — user-defined grouping. Carries a user-entered **`type`** (work /
  leisure, a slug so the set can grow — validated against `Trip::TYPES`) and
  **`color`** (`#rrggbb`, stored exactly as CSS and NcColorPicker use it).
  Both nullable and **never backfilled or inferred**: nothing in an extracted
  booking says whether a trip was for work, and a guessed lozenge would be a
  confident lie. An unclassified trip simply shows none
  (`Version1900Date20260829000000…`).
- `bookings` — one canonical booking per confirmation. There is **no natural key
  column set**: whether a second email describes an existing booking is a
  judgement made in `BookingMatcher` (see `docs/decisions.md`), and the DB only supplies
  candidates (`findMatchCandidates`, indexed by `tm_book_user_ref` and
  `tm_book_user_type_start`). `trip_id` links to a trip;
  **`duplicate_group_id`** is the group of maybe-the-same bookings this one
  belongs to (nullable; the value is the id of the group's oldest member, so no
  sequence or side table is needed and the name survives that member being
  purged). **Two orthogonal state axes** (see `docs/decisions.md`):
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

There is **no `segments` table** — journeys/car rentals/hotels have genuinely
different shapes, so each type's structure (legs + passengers for flight/train/
bus, car supplier/features/pickup/dropoff, hotel stay/guests) is stored as
validated JSON in `bookings.details`. The shape can evolve from the prompt + `ExtractionService`
without a DB migration (that is the whole point of the JSON approach).

## Dev/debug tooling (Personal settings → "Developer & debugging")
`DevController` (`/api/dev/*`, all `NoAdminRequired`, current-user-scoped) backs a
debug panel for iterating without waiting for cron:
- **Read mailbox now** — `POST /api/dev/ingest` runs `IngestionService::ingestForUser`
  synchronously for the current user (bypasses the dispatcher/enabled fan-out;
  still requires the mailbox to be *configured*). It **queues** new mail and then
  pumps the queue, so messages go to the model at once as far as the limits
  allow and the activity log says why the rest wait; **model responses still
  arrive later** via the task-event listeners, so the UI hint says to refresh
  the log after a moment.
- **Activity log** — `IngestionLogger` writes a per-user, step-by-step row
  (`connect`/`fetch`/`dedup`/`schedule`/`llm_response`/`persist`/`wipe`/`backup`) to the
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

## Verified NC 33 API facts
- `OCP\TaskProcessing\IManager::scheduleTask(Task)`; `Task(taskTypeId, input,
  appId, ?userId, customId='')`; results via `TaskSuccessfulEvent`/`TaskFailedEvent`
  (`$event->getTask()->getOutput()`, `getErrorMessage()`).
- Task type class is `OCP\TaskProcessing\TaskTypes\TextToText` (ID `core:text2text`).
- Provider selection per task type is **admin-global** (no per-task provider field).
- `Horde_Imap_Client` lives in the **Mail app's** vendor dir, **not** server core →
  we must vendor our own (back-end-only). Mail app is **not** a prerequisite.
