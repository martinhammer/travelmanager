# Backlog

Open work for this project, including where it deviates from the shared skills
(the `nextcloud` plugin and `~/Code/claude-config/skills`). Not loaded at session
start. One line per item: date, the skill it concerns, what to do.

## Deviations from shared skills
- 2026-11-24 · responsive-layout · List steps are 600/800/1150px of content width instead of Nextcloud's 512/1024; the heading beside the navigation toggle switches at a 620px window width.
- 2026-11-24 · php-backend · `tests/psalm-baseline.xml` exists besides inline suppressions; check it holds only framework false positives.
- 2026-11-24 · eslint-v9-migration · Still on `@nextcloud/eslint-config` 8 with `.eslintrc.cjs` (extends `/vue3`). Migrate to v9 (flat config).

## Next steps

- ⏳ **Next step:** run flights **end-to-end** for one user against a live
  mailbox + Task Processing provider (the path is all wired — ingestion →
  schedule → listener → draft; use "Read mailbox now" + the activity log to watch
  each step), then tune the extraction prompt against the `dropped` rows in the
  Messages view, then enable multi-user fan-out. Scanned PDF attachments (OCR)
  and Calendar/Notes projection remain deferred.

Build/implement order from here: end-to-end flight extraction (single user) →
prompt tuning on real emails → **archive sweep + `body_text`/`last_response` GC**
→ multi-user fan-out.
