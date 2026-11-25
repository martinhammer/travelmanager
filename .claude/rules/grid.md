---
paths:
  - "src/grid.ts"
  - "src/grid.css"
  - "src/BookingsView.vue"
  - "src/TripsView.vue"
  - "src/MessagesView.vue"
  - "src/GridSortMenu.vue"
---

# List grids

**All three list views are the same grid** (the calendar is not one of them — see
`.claude/rules/calendar.md`). The shared look lives in **`src/grid.css`**
as plain `tm-`-prefixed classes (`.tm-rows`, `.tm-row`, `.tm-row-summary`,
`.tm-grid-header`, `.tm-column-heading`, `.tm-chevron`, `.tm-row-body`,
`.tm-cell-text`/`.tm-cell-meta`/`.tm-cell-status`, `.tm-open-link`, `.tm-badge…`,
`.tm-list…`), imported once by `App.vue`. Plain CSS rather than a module because
`<style module>` is per-SFC and all three views need these. Each view's own
`<style module>` holds **only** its `grid-template-columns` (`.columns`), the
`nth-child` rules that drop columns at each width step, and its compact card's
area map (see "Narrow widths" below). Sort behaviour is shared via
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
- **Travel dates has three sort modes, and the default is not a plain date
  sort.** `sortBookings('travel', 'smart')` puts what is under way or coming up
  first (soonest start first), *then past travel in reverse*, undated last —
  ascending by date would bury what is coming up under everything that already
  happened. "Past" means **ended** (`endDate ?? startDate`, by calendar day), as
  in `tripPeriod`: a stay that began yesterday is current, not history. Plain
  `'desc'` and `'asc'` are ordinary chronologies by start. The heading cycles
  smart → desc → asc (`BOOKING_COLUMNS[].cycle`, which `nextSortDirection`
  steps through; columns without one just flip), marked ◆ / ▼ / ▲ — a diamond,
  not an arrow, because smart mode runs forward from today and then backward
  through the past, so any arrow misdescribes most of the list. The heading
  carries a `title` naming the mode, because the marker is `aria-hidden`. The
  mode types are `BookingSortDirection` and `TripSortDirection`; `SortColumn`
  is generic over them so Messages never sees `'smart'`. The tooltip wording is
  shared, `travelSortLabel` in `labels.ts`.
- **The Trips grid sorts the same way, and opens on it** (2026-11-13).
  `sortTrips('travel', 'smart')` ranks by each row's own `period`, so the order
  and the Current/Future/Past chips cannot disagree about what is past; undated
  trips go last by name. It was a plain chronology before, on the argument that
  the chips already answer "what is coming up" — but All is the view people
  open on, and there the plain order put two years of past trips above the next
  one.
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
  have. Sort state is a `sort` + `direction` pair per view (held in
  `navigation.ts`, see `.claude/rules/frontend.md`); the `*_COLUMNS` array
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

## Narrow widths

- **The list grids respond to their container, not the viewport.** The three
  list views' root carries **`.tm-grid-view`**, a container named `tm-grid`.
  Every column step is an `@container tm-grid (…)` rule, so the grids also drop
  columns when the detail sidebar opens. The calendar keeps its own `tm-cal`
  container, and this is not on `.tm-content` because the calendar carries that
  class too.
- **Steps are exclusive ranges** (`600px <= width < 800px`), so the compact
  layout never has to undo a wider step's `display: none`. Each threshold is the
  step's fixed widths + gaps + row padding + a floor for the flexible column, so
  recompute it when a column width changes.
- **Below 600px every list is in compact mode.** `grid.css` hides the heading row,
  shows the sort menu, gives rows a 2px row gap, wraps badges to the end, and puts
  `.tm-chips` on one line that scrolls sideways. Each view module turns its row
  into a **two-line card** with `grid-template-areas`: the same cells in the
  same order, placed by `nth-child`. The first line is the row's identity and
  status, the second its dates and context. **The literal `600px` appears in
  `grid.css` and in all three view modules**, because a container query cannot
  take a custom property, so change all four together.
- **`GridSortMenu.vue`** is the headings as an `NcActions` menu of radio
  `NcActionButton`s. It is built from the view's own `columns`/`sort`/`direction`
  and emits `select` into the view's `onSortColumn`, so it adds no sort logic.
  It listens to `@click`, not `update:modelValue`, because the radio only emits
  on a change and re-picking the checked column must flip it (or cycle Travel
  dates). The order is said in words (the item's `description` and the trigger's
  name), with the ▲/▼/◆ marker in the icon slot. Its class sits on a wrapper
  `<span>` it owns, so `grid.css` can toggle it without outranking NcActions.
- **Row hover is `@media (hover: hover)` only.** A touch screen keeps `:hover`
  on the last tapped row, which reads as a selection.
