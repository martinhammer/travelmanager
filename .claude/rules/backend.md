---
paths:
  - "lib/**"
---

# Backend gotchas

- **App config key for the pipeline flag is `pipeline_enabled`**, never
  `enabled` (core's key; see the php-backend skill). `Version2500Date20260918000000`
  repairs instances that once saved the wrong key. The per-user
  `USER_ENABLED = 'enabled'` in `oc_preferences` is fine.
- **`getImapPassword()` returns null when the credential will not decrypt**
  (instance `secret` changed), so the settings panel still loads and the user
  can re-enter it. It does not delete the row. Apply the same rule to any
  future secret.
- **`ConfigService` stores per-user values as strings**, so the `'1'`
  enabled-flag fan-out (`searchUsersByValueString`) keeps matching.
- **Task Processing:** use `getAvailableTaskTypes()` (long-standing) to probe for
  a provider. `getAvailableTaskTypeIds()` is a recent addition (server PR #54848)
  and may not exist on NC 33.
- **Trip type and colour are cleared with `''`, not `null`.** `TripController::update`
  filters out nulls so an omitted field keeps its value; that makes null mean
  "not supplied", so the empty string has to carry "clear it". `BookingService`
  treats `''` as null on the way in. The frontend keeps `''` throughout for the
  same reason — except at `NcColorPicker`, which models `string | undefined` and
  treats `''` as neither a colour nor none, so it is mapped at that one boundary.
- **Text from an email or a model is fitted to its column; text a user typed is
  refused.** MySQL in strict mode refuses an over-long `VARCHAR(255)`, PostgreSQL
  a NUL byte, both invalid UTF-8 — and **SQLite, which CI runs PHPUnit on, refuses
  none of them**, so the suite cannot see it. A refusal costs more than the value:
  inside the mailbox read it stopped the run on that message every time, and in
  `applyExtraction` it failed the email on every retry. So `Db/ColumnText::fit`
  (scrub, strip NUL, cut by character) is applied to subject, sender, mailbox and
  the model's provider/reference/title, and `clean` to `body_text`. Edits and
  trip names go through `ColumnText::fits` and a 400 instead: cutting what
  someone just typed saves something they did not enter. The mailbox name is
  refused at save, since every stored message records it. **Each message in a
  read is now stored on its own** (`IngestionService::ingestForUser`): one that
  still fails is logged and retried next run, a unique-key violation from a
  concurrent read is treated as already stored, and the queue always gets its
  turn (`finally`).
