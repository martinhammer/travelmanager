---
paths:
  - "src/**"
  - "tests/frontend/**"
---

# Frontend layout (`src/`)

```
App.vue              shell only: nav, which view is showing, the detail panel
├─ NavCounter.vue    one nav row's count: lozenge for the queue, muted total
├─ RefreshButton.vue re-read everything: nav header (wide) or each toolbar (phone)
├─ CalendarView.vue  the month grid, its toolbar and month summary
│   └─ CalendarBar.vue    one trip/booking bar: its icon, colour and draft cue
├─ BookingsView.vue  grid only — no dialogs, no actions
├─ TripsView.vue     grid + the "New trip" button
├─ MessagesView.vue  grid + the expandable diagnostic body
│   (all three)  └─ GridSortMenu.vue   the column headings as a menu, compact only
├─ DetailSidebar.vue  one panel for booking | trip | message, incl. its Actions
│   └─ BookingDetails.vue   a booking's type-specific body
└─ AppDialogs.vue    every dialog, rendered once
    └─ TripPickerDialog.vue   confirm-a-draft / add-to-trip

grid.css        the look every list grid shares (see `.claude/rules/grid.md`)
calendar.css    the month grid's own look, shared by the two calendar SFCs
store.ts        bookings / trips / messages / loading / reload + derived counts
navigation.ts   the route, open/close/back, hash + history plumbing
dialogs.ts      which dialog is open, on what, and the openX() calls
labels.ts       how the domain is worded
grid.ts messages.ts bookings.ts trips.ts detail.ts calendar.ts backup.ts latest.ts   pure, unit-tested
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
  **Only the latest `reload()` writes** (`latestOnly` in `latest.ts`): two
  actions in a row start two, and the older response settling last used to
  replace the newer list.
- **A button that writes is disabled from the click until its request returns**
  (2026-11-12, review item 15): the delete and trip dialogs, the sidebar's
  Actions and "Not a duplicate", and Retry/Discard per Messages row. Set the flag
  before the first `await`. The visible state only changes once the reload
  lands, so until then a second click repeated the request: a second delete
  404'd after the success toast, and a second create made a second trip.
- **`dialogs.ts` is the same pattern for *what is open*, and `AppDialogs.vue`
  renders the lot.** Most dialogs are now raised from the detail panel and one
  ("New trip") from the Trips toolbar, so no view owns them; threading them
  through `App.vue` would put the shell in the middle of every action. It holds
  only refs and `openX()` calls — what a confirm button *does* lives in the SFC.
- **`labels.ts` is the one module that may import `@nextcloud/l10n`** and so is
  *not* unit-testable (see "Frontend tests" below). That is the trade for every view wording things
  identically. Keep decisions out of it — anything with a branch belongs in a
  pure module where it gets tested.
- **Each view has its own filter and sort state.** Do not conflate view and filter
  again — the original single `filter` ref made "All bookings" a status and
  "Trips" a view. **That state is not held in the view** (`bookingsList`/
  `tripsList`/`messagesList` in `navigation.ts`, beside `calendarMonth`):
  `App.vue` mounts one view at a time, so refs in the view went back to their
  defaults on every visit to another (2026-11-13). Like the month, it survives
  a view switch but not a reload. Two things that rely on a fresh "All" had to
  be handled once it stopped being fresh: a remembered booking type whose last
  booking is gone is reset (the chip that would clear it is hidden below two
  types), and **opening a record a remembered filter hides clears that filter**,
  in all three lists (a `watch` on `route.detail`). It fires on *opening* — from
  a row, a link in the panel or another view — and never on a data change:
  confirming a draft while filtered to Drafts must not throw the queue back to
  All after every confirm. For Messages it also has to run before
  `revealRoutedMessage`, since the row is the only place an email's prompt and
  response are shown. All filtering/sorting is client-side and pure
  (`sortBookings`/`filterBookings`, `sortMessages`/`filterMessagesByStatus`,
  `sortTrips`/`filterTripsByPeriod`/`tripRows`, shared helpers in `src/grid.ts`).

## Gotchas

- **Trip type and colour are cleared with `''`, not `null`** (see
  `.claude/rules/backend.md`). Keep `''` throughout; map it only at
  `NcColorPicker`, which models `string | undefined`.
- **Column headings would not hide at a breakpoint, and this hit desktop too.**
  The view modules' `.columns > *:nth-child(n) { display: none }` is (0,2,0).
  The headings also match `.tm-grid-header .tm-column-heading { display: flex }`
  in `grid.css`, also (0,2,0), and that rule lands later in the built CSS. So the
  data cells hid and the heading buttons did not: every heading row below the
  widest step had too many cells, wrapped onto extra lines and sat over the
  wrong columns. The hide rules are now **`.columns.columns > …`** (0,3,0). It is
  the same chunk-order trap as `.tm-notice.tm-notice`: win on specificity,
  never on order. **So never double `.tm-column-heading`'s main rule**: at
  (0,3,0) it ties the hide rules and wins on order again. The heading's
  `min-height: 0` and negative margin, which have to beat the server's (0,2,1)
  button rules, sit in a doubled rule of their own that sets no `display`
  (2026-11-13, review item 16).
- **Frontend tests** live in `tests/frontend/**.spec.ts` (Vitest, `happy-dom`),
  run via `npm run test:frontend` / `make test`. Keep testable logic in plain
  `.ts` modules (e.g. `src/bookings.ts`) and use `import type` for cross-module
  types so the test doesn't pull `@nextcloud/*` runtime imports. `vitest.config.ts`
  is intentionally separate from `vite.config.ts`. **`labels.ts` and `store.ts`
  are the deliberate exceptions** — they import `@nextcloud/*` and are therefore
  untested; keep them free of anything worth testing.
