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
       └─ BookingService.applyExtraction()      # writes DRAFT bookings + segments
UI (Vue, OCS API)
  └─ list drafts → confirm / edit / discard ; group bookings into trips
```

Key classes (all under `OCA\TravelManager`, `lib/`):
- `Service/ExtractionService` — **pure, dependency-free** prompt build + JSON
  repair + validation. This is the most-tested unit.
- `Service/IngestionService`, `Service/ExtractionResultHandler`, `Service/BookingService`, `Service/ConfigService`.
- `Llm/ILlmService` → `TaskProcessingLlmService` (single platform strategy).
- `Imap/IImapClient` → `StubImapClient` (**stub — no network I/O yet**).
- `BackgroundJob/DispatcherJob`, `BackgroundJob/UserIngestionJob`.
- `Listener/TaskSuccessfulListener`, `Listener/TaskFailedListener`.
- `Db/*` entities + `QBMapper` mappers; `Migration/Version1000Date20260621000000`.
- `Controller/{Booking,Trip,Settings,Admin}Controller`; `Settings/*`.

### Data model (5 tables, prefix `travelmanager_`)
- `messages` — IMAP dedup + ingestion audit; unique `(user_id, message_id)`.
- `trips` — user-defined grouping.
- `bookings` — one canonical booking per confirmation; natural key
  `(user_id, type, provider, booking_reference)` drives update/cancel idempotency;
  `trip_id` links to a trip; `status` = draft/confirmed/cancelled/superseded.
- `segments` — dated items (flight leg / hotel stay / rental period).
- `tasks` — Task Processing `task_id` → `(user_id, message_id)` correlation.

### Extraction JSON contract
`core:text2text` returns **raw text only** (no JSON/function-calling mode), so
every response goes through `ExtractionService`: strip fences → extract first
balanced `{…}` → `json_decode` → validate (`type` in allowlist, ≥1 segment with a
parseable `start_local`; bookings with no dated segment are dropped as
anti-hallucination). Output shape: `{ "bookings": [ { type, provider,
booking_reference, status, title, confidence, segments:[ { start_local,
start_timezone, end_local, ..., flight_number, carrier, ... } ] } ] }`.

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
- **Background jobs run in system context**, acting on behalf of each user: Task
  Processing tasks carry the `userId`; correlation rides on `customId` + the
  `tasks` table.
- Secrets (IMAP app password) only via `ICredentialsManager`, encrypted, keyed by
  uid — never in app/user config, never logged.

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
- ✅ `ExtractionServiceTest` — 12 tests pass standalone.
- ⏳ `IImapClient` is `StubImapClient` (throws). **Next step:** vendor
  `Horde_Imap_Client`, implement the real read-only client, swap the binding in
  `Application::register()`, run flights end-to-end for one user, then enable
  multi-user.

Build/implement order from here: IMAP ingestion + dedup → flight extraction
end-to-end (single user) → draft/confirm UI polish → multi-user fan-out.

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
  User config still goes through `IConfig` (`getUserValue`, `setUserValue`, and
  `getUsersForUserValue` for the enrolled-user fan-out — these are not deprecated).
- **Task Processing:** use `getAvailableTaskTypes()` (long-standing) to probe for
  a provider. `getAvailableTaskTypeIds()` is a recent addition (server PR #54848)
  and may not exist on NC 33.
- **Settings registration** is via `info.xml` `<settings>` (admin/admin-section/
  personal/personal-section) and `<background-jobs>`. **Bump the app version** when
  adding settings so Nextcloud re-registers them.
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
- **`@nextcloud/vue` 9 API drift** — caught by `vue-tsc`/eslint, not at runtime:
  `NcButton` styling prop is **`variant`** (`primary`/`secondary`/`tertiary`/…),
  **not** `type` (which is now the native button type). **No `.sync`** in Vue 3 —
  use `v-model:propName` (e.g. `v-model:value` on `NcTextField`). Always run
  `npm run type-check` after touching `.vue` files.
- **`@nextcloud/dialogs` must be ^7** (v6 pins a `@nextcloud/vue ^8` peer that
  conflicts with v9 and blocks `npm install`). v7 dropped the `@nextcloud/vue`
  peer.
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
- **Security:** the original brief contained a live **OpenRouter API key** — it is
  **exposed/compromised, must be rotated, and is not used anywhere** (the app
  routes through Nextcloud Task Processing, never a hard-coded provider). Never add
  provider keys to this app.

## 8. Conventions

OCP interfaces only (no `OC\` internals); constructor DI; DB via query
builder + migrations; secrets via `ICredentialsManager`; feature-flag the whole
pipeline (global `IAppConfig` flag + per-user enabled flag). Mock the LLM and
IMAP boundaries in tests.
