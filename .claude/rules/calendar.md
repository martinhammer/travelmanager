---
paths:
  - "src/CalendarView.vue"
  - "src/CalendarBar.vue"
  - "src/calendar.ts"
  - "src/calendar.css"
---

# Calendar

The month grid, and the view the app opens on. Built on the same principle as the
rest: *what is open* is a value in the URL, the layout maths is pure and tested,
and the wording lives in `labels.ts`.

- **Clicking a bar does not leave the calendar — except for a message.** The hash
  grammar therefore has a second form: `#/calendar/bookings/42` alongside the
  existing `#/bookings/42` shorthand. `keepsView(view, type)` takes the *type* as
  well as the view for this reason: a message is an email, not something that
  happens on a day, so holding the month behind one shows a grid with no bearing
  on the panel. Opening a message hands over to the Messages list, which
  **expands the row on arrival** (`revealRoutedMessage`) — the prompt and the raw
  model response live there and nowhere else. That nudge is imperative and
  `onMounted`-only: a `:open` binding would fight the user's own collapsing, and
  running it on every route change would merge the list's two deliberately
  separate affordances (summary click toggles, subject click opens the panel). `detail.ts` grew `ViewName`, a three-segment branch in `matchRoute`,
  a `within` argument on `detailRoute`, and **`keepsView(view)`** — true only for
  the calendar. `formatRoute` **collapses back to the shorthand** whenever the
  entity is shown over its own list, so existing URLs keep working *and* keep
  being the ones we generate. **Why the calendar is special:** the lists are each
  about one kind of thing, so opening a different kind there genuinely means you
  have left; the month is the thing you work *from*, and taking it off screen on
  every click makes stepping through a trip a round trip through the Bookings
  list each time. `DEFAULT_ROUTE` is now `#/calendar`.
- **`NcAppSidebar` shrinks the content area, it does not cover it**
  (`position: relative`, `width: clamp(300px, 27vw, 500px)`, and `.app-content`
  has `min-width: 0`; below 512px it becomes a full-screen overlay). At a 1512px
  viewport the grid keeps ~800px with the panel open. That squeeze is safe *here*
  in a way it is not for the list grids: every bar's day and lane are fixed by its
  dates, so nothing moves to another day — only the label truncates.
- **Compaction is a `@container` query, not a media query.** The viewport does not
  change when the sidebar opens, so a media query never fires at the one moment
  the grid most needs to compact. `.tm-cal` is the container; below 640px the bar
  labels drop and the icon carries the meaning. This is why every bar has an
  **`aria-label`**: `display: none` takes the visible label out of the
  accessibility tree too, so the button would otherwise be unnamed exactly when it
  is hardest to identify by sight.
- **A trip's colour takes over its bars.** A trip states its colour outright; its
  bookings take a **paler** version — `color-mix` toward `--color-main-background`,
  so it pales on a light theme and darkens on a dark one instead of glaring.
  `CalendarItem.color` carries the raw colour (its own for a trip, its trip's for a
  booking, null for an unfiled booking or an uncoloured trip, which then fall back
  to `--color-primary-element`); `CalendarBar` passes it through as `--tm-cal-trip`
  and
  **paling is left to CSS**, since only the stylesheet knows what the background
  is. Three consequences:
  - **A trip bar's text colour is computed** (`contrastingText`). The type palette
    is picked to carry white at ~5:1, but a trip colour comes from Nextcloud's
    picker, which offers pale yellows — white on those is unreadable. A *booking*
    bar needs no such computation: a fill that close to the background simply takes
    `--color-main-text`.
  - **A pale bar gets a border** of a stronger mix of the same colour. Without one
    it has no edge and floats on the day cell.
  - **There is no booking-type palette any more.** Colour on this view means one
    thing only — which trip — so a bar with no trip colour takes the theme accent
    rather than a per-type one, and an uncoloured trip and its bookings read as
    one family exactly as a coloured one does. **This is why every bar has a type
    icon**: it is now the only thing that says what kind of booking it is.
- **Type is carried entirely by the icon** (`vue-material-design-icons`: plane /
  bed / car, MapMarker as the fallback), since colour now means which trip. Every
  bar has one for exactly that reason.
- **A trip's icon is its own type, and the three stay one family** (2026-09-17):
  briefcase = work, backpack (`BagPersonal`) = leisure, plain suitcase =
  unclassified, declared in `CalendarBar` beside the booking-type map because the
  bars are the only place they are drawn. It was briefly mirrored on the Trips
  grid's type lozenge and taken back out: the booking-type lozenges beside it are
  words, so one lozenge in three carrying a glyph read as an inconsistency rather
  than as a distinction. The suitcase is what says *trip, not booking* — its partners are the plane, the
  bed and the car, and below 640px it is all the container query leaves — so the
  swap had to **refine** that marker rather than spend it; a necktie or a parasol
  would have read as a fourth kind of thing. Literal metaphors were mocked up
  beside it and lost on that point, not on looks. The unclassified suitcase is the
  **common** case, not a fallback for a broken one: `trips.type` is nullable and
  never inferred, so it must look deliberate. `CalendarItem.type` now means "the
  slug that picks this bar's glyph" — a booking type for a booking, work/leisure
  for a trip — one field rather than two, since `kind` is consulted first and the
  two vocabularies never meet. A trip bar's `aria-label` gained the type for the
  usual reason: an icon is not in the accessibility tree.
- **Draft is a dashed outline**, not a paler shade — a paler shade is what a
  *booking* already is, and the cue has to survive greyscale and colour-blindness
  besides.
- **Discarded is muted *and* struck through** (2026-09-17). Shown only by the
  archived & discarded switch, it then has to be told apart from an **unfiled**
  booking — the bar it otherwise matches exactly, since discarding unlinks a
  booking from its trip and leaves it on the default tint. So the fill drops out
  of the trip palette to `--color-text-maxcontrast` and the label is struck
  through; grey alone would not have separated them, and the strikethrough is the
  half that survives greyscale and says *rejected* rather than *unfiled*.
  `CalendarBar` withholds the inline `--tm-cal-trip` for a discarded bar as well,
  since an inline custom property outranks the stylesheet and rows discarded
  before the unlink rule still carry a trip id. **Archived is deliberately left
  alone**: it keeps its trip, and its colour, because that is what archiving
  means.
- **Trip against booking is fill strength, weight and icon** — full colour, bold,
  suitcase — **not shape**. Bars all share one corner radius; a pill for trips
  made the distinction louder than it needs to be once the fill carries it. (The
  one remaining `--border-radius-pill` in calendar.css is the today circle.)
- **Colours adapt to the theme rather than being duplicated per theme.** The trip
  fill is the user's own or `--color-primary-element`; a booking's is mixed toward
  `--color-main-background` and a draft's edge toward `--color-main-text`, so both
  follow light and dark with no theme hook to keep in step.
- **A multi-leg journey draws one bar per leg** — flight, train or coach alike —
  each spanning that leg's own departure→arrival dates (`bookingItems`/`legItems`,
  gated on the same `SEGMENTED_TYPES` list the extraction uses). `bookings.start_date`
  /`end_date` is the *whole itinerary*, so a return trip drawn from it is a single
  bar covering the fortnight you were away — which says nothing about when you were
  flying and buries every other booking under it. Consequences:
  - `CalendarItem.id` is **not unique** (all four legs carry the booking's id, so
    any leg opens it and all four highlight together); **`CalendarItem.key`** is,
    and is what the `v-for` keys on and what `compareItems` breaks ties by. An
    id-based tiebreak is not a total order and let legs swap lanes between renders.
  - **`monthSummary` counts distinct ids, not bars** — a four-leg flight is one
    booking you have to make one decision about.
  - Each leg is labelled by `segmentLabel` ("EY42 AMS → AUH"), not by the booking
    title, which would otherwise repeat a long subject line four times. The flight
    number is preferred over the carrier because it already carries the airline
    code. Single-leg flights get the same treatment, for consistency and because
    a route beats a truncated email subject in a ~150px cell.
  - Falls back to the stored span when a flight has no readable segments, and
    skips an individual leg with no `departureLocal` (the field extraction
    validates a segment by). Hotels and car rentals are never split — a stay is
    one continuous thing.
- **Trip bars use the derived span** (`tripRows`/`tripSpan`), never
  `trips.start_date` — same rule as the Trips grid, so the two cannot disagree.
  A trip with no dated bookings has no span and simply is not on the calendar.
- **Discarded and archived are hidden by default.** On a list they merely sit
  there; here they would take lanes from the bookings that matter. An
  `NcCheckboxRadioSwitch type="switch"` in the toolbar reveals them — a switch
  rather than a button because it turns a persistent condition on and off rather
  than performing an action, and its state then reads directly instead of having
  to be inferred from a button's variant. **At the 640px compact step it becomes
  an icon button** (`.tm-cal-show-switch` / `.tm-cal-show-toggle`, both bound to
  `showAll`): `ArchiveOffOutline` while hidden, `ArchiveEyeOutline` while shown,
  named for the action ("Show / Hide archived & discarded"), no `aria-pressed`.
  It shares `.tm-cal-end` with Refresh (phone only), toggle first. Decision:
  "Toolbar controls on narrow widths".
- **The month summary is scoped to the month on screen** ("2 trips · 5 bookings ·
  1 draft"), which is the one count the navigation's global counters cannot give.
  The draft count is a **button** that opens the earliest draft in the month, so
  it is something you act on rather than a number you carry elsewhere.
- **Week rows are `minmax(min-content, 1fr)`, never `minmax(<length>, 1fr)`.** A
  `1fr` *maximum* is a flexible track: it is sized by distributing free space and
  never by what is in it, so a fixed minimum lets a week with more lanes than its
  share overflow into the week below — bars landing on the next row's day numbers,
  with no warning. Measured: a row needing 226px given 119px spills 107px. With
  `min-content` the floor is what the week actually needs and the flex share still
  fills the screen. `--tm-cal-week-min` moved onto `.tm-cal-week` accordingly,
  where it feeds that min-content instead of capping it, and now means only "the
  floor an empty week sits at".
- **The lane geometry is declared once, in CSS, as explicit lengths.**
  `--tm-cal-bar-height: 20px`, `--tm-cal-lane-gap: 2px`, `--tm-cal-head: 22px`
  (the day-number row) and `--tm-cal-foot: 3px` are set on `.tm-cal` and *applied*
  as real `height`s, not left to however a line box rounds. `CalendarView` reads
  all four back with `getComputedStyle` to work out how many lanes fit, so the
  arithmetic and the pixels cannot drift. Change the bar height and everything
  follows; hard-code a pitch in JS and it will not.
- **The lane cap is measured, not a constant** (`lanesForHeight`). A week row is
  `minmax(min-content, 1fr)`, so its height depends on the window and on how many
  weeks the month has — anywhere from ~114px to ~250px. A fixed cap says "+3 more"
  over visible empty space on a tall screen. `CalendarView` observes the grid with
  a `ResizeObserver` and feeds the *equal share* (grid height ÷ weeks) in; a
  measured row would be circular, since the cap decides the content and the content
  decides the row. Observing the grid is safe because its height comes from the
  window (it is the `flex: 1` child that scrolls), so the cap never feeds back.
  - **`MIN_LANES` (6) is a floor the share can only raise.** Because a row grows
    past its share and the grid scrolls, capping at the nominal share would hide
    bookings the layout was perfectly willing to draw.
  - `DEFAULT_MAX_LANES` is now only the value for the first render, before the
    observer has reported.
- **`+N more` expands the week in place** rather than opening a popover: the
  hidden items stay on the day they belong to and there is no floating layer to
  dismiss. `layoutMonth` takes a per-week lane cap for exactly this. The cap is
  **soft** — a week needing one lane more keeps it, since "+1 more" would occupy
  the very row it saved.
- **The grid stays even when empty** — a calendar with no days is not a calendar —
  so the empty case is a line in the summary, not an `NcEmptyContent` replacing
  the view as the lists do.
- **Day backgrounds are their own layer** (`.tm-cal-week-bg` absolutely positioned
  under `.tm-cal-week-fg`). A cell spanning every lane inside one grid would need
  the lane count in `grid-template-rows`, and `repeat()` cannot take a custom
  property.
- **The month heading's hidden `.tm-cal-month-ghost` spans are load-bearing.**
  The `<h2>` is a one-cell grid holding the visible label *and* a hidden copy of
  every `getMonthNames()` entry, so it is always as wide as the widest month it
  could show and the ‹ › Today buttons never shift as you page (measured: 12
  distinct button positions before, 1 after). `visibility: hidden`, never
  `display: none` — a hidden box still sizes the grid, which is the whole point,
  and it stays out of the accessibility tree so the heading reads as one month.
  A hard-coded `min-width` cannot replace this: month-name lengths differ wildly
  by locale and font. `tabular-nums` stops the box twitching on a year change.
- Week start and day/month names come from `@nextcloud/l10n` (`getFirstDay`,
  `getDayNamesMin`, `getMonthNames`), so a Sunday-first locale is right for free;
  `calendar.ts` takes `firstDay` as a **parameter** so it stays pure and tested.
- The displayed month is **`calendarMonth` in `navigation.ts`**, not a
  component ref and **not** in the hash. `App.vue` mounts one view at a time
  (`v-if`), so a ref inside `CalendarView` went back to today on every visit to
  another view; a module-level ref survives the switch but not a reload —
  deliberate for now; put it in the route if month links ever need sharing
  (2026-11-13, review item 23).
- **On a phone the toolbar still fits on one line** (`@container tm-cal
  (max-width: 420px)`): the month heading steps down to 1.25rem and to **short
  month names with the full year** (`getMonthNamesShort()`; the heading holds a
  long and a short set, label plus ghosts, and shows one with `display`), and
  **Today** shows only its `CalendarToday` icon. That leaves room for the
  archive toggle and Refresh at the end of the line. The button always carries the icon and an
  `aria-label`; on a wide calendar `calendar.css` hides the icon and restores
  NcButton's text-only padding, so the desktop look is unchanged. The ghost months
  stay at every width.
- **Coarse pointers get 24px bars** (`@media (pointer: coarse)`: bar 24px, gap
  3px, week floor 134px = four lanes), because 20px is under WCAG 2.2's 24px
  target size. `CalendarView` reads the variables back, so no script changes.
- **Size-dependent variables go on `.tm-cal-grid`, not on `.tm-cal`.** `.tm-cal`
  is the `tm-cal` container, and a container query never matches its own
  container, so `--tm-cal-week-min: 106px` set on `.tm-cal` inside
  `@container tm-cal (max-width: 640px)` never applied until it moved to the
  grid (2026-11-27). The coarse-pointer 134px is repeated as
  `.tm-cal .tm-cal-grid` so touch screens keep it on a narrow calendar.
