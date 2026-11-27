# Backlog

Open work for this project, including where it deviates from the shared skills
(the `nextcloud` plugin and `~/Code/claude-config/skills`). Not loaded at session
start. One line per item: date, the skill it concerns, what to do.

## Deviations from shared skills
- 2026-11-24 · responsive-layout · List steps are 600/800/1150px of content width instead of Nextcloud's 512/1024; the heading beside the navigation toggle switches at a 620px window width.
- 2026-11-24 · php-backend · `tests/psalm-baseline.xml` exists besides inline suppressions; check it holds only framework false positives.
- 2026-11-24 · eslint-v9-migration · Still on `@nextcloud/eslint-config` 8 with `.eslintrc.cjs` (extends `/vue3`). Migrate to v9 (flat config).

## Next steps

- 2026-11-27 · Manual booking: possibly an "Add booking" for bookings that have
  no confirmation email (accommodation "staying with a friend"). Undecided. If
  built, its button goes in the navigation header beside Refresh, which today
  sits there alone (see "Refresh re-reads, and sits in the navigation" in
  `docs/decisions.md`); on a phone it would need a toolbar home like Refresh's.
- 2026-11-27 · Search: possibly a search across bookings, trips and messages.
  Undecided. If built, use `NcAppNavigationSearch` in the navigation's `#search`
  slot and move Refresh (and any "Add booking") into its `actions` slot, which
  it hides while the field has focus. Settle first what a search does in each
  view (filter the list on screen, or jump to a record) and where it goes on a
  phone, where the navigation is a closed drawer.
- 2026-11-27 · Calendar heading order: the month heading is always
  `${month} ${year}`, which is wrong for locales that put the year first
  (Japanese, Hungarian). Format it with `Intl.DateTimeFormat` (`month: 'long'` /
  `'short'`, `year: 'numeric'`) in the user's locale, ghost copies included.
- 2026-11-27 · Refresh follow-ups, not planned: a "check mailbox now" action in
  the main UI (today only the unpaced `dev/ingest` debugging tool; it would need
  pacing against the background job), refreshing on its
  own when the tab becomes visible again, and an `r` shortcut as in Mail.

- ⏳ **Next step:** run flights **end-to-end** for one user against a live
  mailbox + Task Processing provider (the path is all wired — ingestion →
  schedule → listener → draft; use "Read mailbox now" + the activity log to watch
  each step), then tune the extraction prompt against the `dropped` rows in the
  Messages view, then enable multi-user fan-out. Scanned PDF attachments (OCR)
  and Calendar/Notes projection remain deferred.

Build/implement order from here: end-to-end flight extraction (single user) →
prompt tuning on real emails → **archive sweep + `body_text`/`last_response` GC**
→ multi-user fan-out.
