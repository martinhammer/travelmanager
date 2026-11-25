# CLAUDE.md — Travel Manager

A self-hosted **Nextcloud app (33–36, per `appinfo/info.xml`)** that reads
travel-booking emails from a per-user dedicated mailbox, extracts structured
booking data with an LLM, and shows it in the app UI (Calendar/Notes projection
later). MVP booking types: **flight, accommodation, car rental, train, bus**;
train and bus share the flight shape.

Detail lives outside this file and loads only when needed:

| Where | What |
|---|---|
| `docs/decisions.md` | Every settled decision with its reasons. Read the entry before changing anything it covers. |
| `docs/architecture.md` | Full data-flow diagram, key classes, data model, dev/debug tooling, verified NC 33 API facts. |
| `docs/backlog.md` | Open work, next steps, deviations from the shared skills. |
| `.claude/rules/*.md` | Load automatically when matching files are touched: `backend` (lib/**), `extraction`, `imap`, `pdf`, `frontend` (src/**), `navigation`, `calendar`, `grid`. |
| nextcloud plugin skills | Platform knowledge shared by all apps (php-backend, vue-frontend, responsive-layout, github-ci, ocs-errors, …). Platform gotchas go there, not here. |

## Invariants

Each names its entry in `docs/decisions.md`. Do not break one without a new decision.

- **Multi-user, hard isolation:** every query is partitioned by `user_id`.
- **One app owns the whole pipeline** (ingestion, classification, extraction,
  data model, future projection). Do not split it into separate apps.
- **The LLM stays outside the app** behind `ILlmService`; the provider is
  **admin-global** via Task Processing ("LLM provider is admin-global").
  **No provider API keys in this app, ever.**
- **The model is shown, never chosen** in the settings panels.
- **IMAP is strictly read-only:** never flag or move messages; dedup by RFC
  `Message-ID` in the DB.
- **Times are local wall-clock** plus an informational IANA tz string; no
  timezone conversion anywhere.
- **Trips are first-class**, with manual booking→trip linking.
- **Draft-then-confirm:** model output is stored as drafts; nothing is pushed
  anywhere until the user confirms.
- **One message = one booking.** A later email about the same booking is
  reported (`messages.status = related`), never applied.
- **Whether two emails describe one booking is a judgement** (`BookingMatcher`),
  not a key.
- **A possible duplicate is a relation between bookings**, not a fact about one.
- **Booking state is two orthogonal axes**, never one column; **a message has
  two axes too** (what the pipeline saw, what the user decided).
- **Failed extractions are retried per message**, not by wiping.
- **Model load is paced by the app** (in-flight cap + hourly budget,
  `QueuePlanner`), not by how much mail is read; messages reach the model
  oldest first.
- **Backup/restore is one user's data in one versioned JSON file.**
- **PDF attachments are read with prinsfrank/pdfparser** plus a column marker.
- **A mailbox connection is verified** and goes only where it was checked.
- **Background jobs run in system context**, acting on behalf of each user.
- **No password confirmation, anywhere.** Do not reintroduce
  `#[PasswordConfirmationRequired]`.
- **The global pipeline flag is `pipeline_enabled`**, never `enabled` (core's key).

## Pipeline (compact)

```
DispatcherJob (TimedJob ~15 min)
  ├─ ExtractionQueue.reconcile() + pump()
  └─ per enrolled user → UserIngestionJob → IngestionService.ingestForUser()
       IMAP fetchRecent (read-only) → dedup → AttachmentService (PDF text)
       → messages.status = queued → ExtractionQueue.pump()
pump(): QueuePlanner picks queued messages → ILlmService.scheduleText2Text
       → Task Processing (core:text2text)
TaskSuccessful/FailedEvent → ExtractionResultHandler
       → ExtractionService.parseAndValidate() → BookingService.applyExtraction()
       → DRAFT bookings; pump() again
UI (Vue, OCS): Calendar (default) | Bookings | Trips | Messages + detail sidebar
```

**Pure, dependency-free units** (most of the tests live here; keep them free of
OCP types): `ExtractionService`, `BookingMatcher`, `QueuePlanner`,
`BackupFormat`, `BackupImportPlanner`, `MessageText`, `Imap/Html`,
`Imap/ImapEndpoint`, `Pdf/PdfLayout`, `Db/ColumnText`.

## Commands

```sh
composer install        # PHP deps + dev tools (bamarni bin plugin)
composer psalm          # static analysis, errorLevel 1, findUnusedCode
composer cs:fix         # php-cs-fixer
composer test:unit      # PHPUnit, standalone or inside a server checkout

npm ci                  # frontend deps (package-lock.json is committed)
npm run lint            # eslint
npm run type-check      # vue-tsc --noEmit (alias ts:check, used by CI)
npm run stylelint
npm run test:frontend   # vitest, tests/frontend/** (alias test, used by CI)
npm run build           # vite build
```

One pure test file standalone:
`php vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php --no-configuration tests/unit/Service/ExtractionServiceTest.php`
(`QueuePlannerTest` and `BookingMatcherTest` run the same way).

Makefile: `make dev`, `make lint`, `make test`, `make psalm`, `make build`,
`make check` (lint + psalm + test + openapi), `make clean` / `make distclean`.
`make package` and `make stage` are release/deploy targets: Martin runs them.

**CI** uses the `nextcloud/.github` org templates, synced and not edited (see the
github-ci skill). This repo's deviations: `update-nextcloud-ocp-matrix.yml.patch`
(removes the owner guard and the self-approve/auto-merge steps) and
`npm-audit-fix.yml.patch` (same steps); `appstore-build-publish.yml` is
deliberately absent (releases are built by hand). The `ts:check` and `test` npm
aliases exist only because the templates call them with `--if-present`: removing
one turns its job green while it tests nothing.

## Runtime prerequisites

- Background jobs in **cron / systemd** mode (not AJAX).
- A `core:text2text` provider installed and selected in the AI admin settings
  (`llm2` local and/or `integration_openai` external). Email content goes to
  that provider. An AI background worker (systemd) for acceptable latency.
- A per-user dedicated mailbox reachable by IMAP with an **app password**
  (OAuth2/XOAUTH2 is deferred).

## Conventions

- OCP interfaces only (no `OC\` internals); constructor DI; DB via query
  builder + migrations; secrets via `ICredentialsManager`.
- Feature-flag the whole pipeline: global `IAppConfig` flag `pipeline_enabled`
  plus a per-user enabled flag.
- Mock the LLM and IMAP boundaries in tests.
- Index names use the `tm_` prefix (identifier length limit).
- `tests/psalm-baseline.xml` holds only framework false positives (DI- and
  `info.xml`-registered classes that look unused); fix real issues instead.
- A new settled decision goes into `docs/decisions.md` (dated) with a one-line
  invariant here; a new platform lesson goes into the nextcloud skills.
