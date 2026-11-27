---
paths:
  - "src/App.vue"
  - "src/navigation.ts"
  - "src/detail.ts"
  - "src/DetailSidebar.vue"
  - "src/NavCounter.vue"
  - "lib/Controller/**"
---

# Navigation, provenance and counters

## Provenance: message ↔ booking ↔ trip

**The user must always be able to see where a piece of information came from.**
Treat navigable links between the three entities as a first-class goal, and weigh
any design decision against whether it preserves or destroys that trail — this is
a recurring consideration, not a one-off feature request.

**Built (phase 1).** `DetailSidebar.vue` is the one place a booking, trip or
message is shown in full, openable from any view — and later from the calendar,
which is why *what is open* is a value rather than a component's internal state:
- **Addressing lives in `location.hash`** (`src/detail.ts`: `parseRoute` /
  `formatRoute` / `detailRoute`) — `#/bookings/42`, `#/trips/7`, `#/messages/19`.
  No vue-router; the route set is tiny and fixed. A malformed hash falls back to
  `DEFAULT_ROUTE` rather than rendering nothing.
- **Back is the browser's own history**, not a second stack: navigating pushes an
  entry, the panel's "←" calls `history.back()`, and its caption rides in
  `history.state.fromLabel` so it survives Back *and* Forward. A ref would
  desync the moment someone used the browser's button.
- **Two ways in, and they differ.** `openDetail` (a list row) clears the trail;
  `openLinked` (a cross-link inside the panel) keeps it. Clicking Trip 2 in the
  list while Trip 1 is open must not offer "← Trip 1" — that describes a journey
  the user did not take. Browser Back still returns either way; it is only the
  panel's own back affordance that resets.
- **`NcAppNavigationItem` renders `<a href="#">`** when given no `href`/`to`, so
  every nav click appends a bare `#` to the history and fires `popstate`. Two
  defences, keep both: the nav items use **`@click.prevent`**, and `onPopState`
  uses **`matchRoute`** (null for anything unrecognised) rather than `parseRoute`
  (which falls back to Bookings). Without them, clicking Trips switched the view
  and the stray hash switched it straight back — the view nav silently stopped
  working.
- `sourceMessageId` is on the booking payload; **`GET /api/messages?messageId=`**
  looks up a source email older than the list's 200-row page (a query param, not
  a path segment — an RFC Message-ID contains `@` and angle brackets, and our OCS
  helper does not escape path segments).
- `BookingDetails.vue` renders a booking's type-specific body. It was shared with
  the expanded grid row until that row was removed; keep it a separate component
  anyway — the calendar will want the same body.
- The panel deliberately has **no tabs**: cross-links are the whole point of the
  feature, and a tab would hide them behind a click.

**Built (phase 2).** `related` messages now carry the ids of the bookings they
matched in **`messages.related_booking_ids`** (`Version1800Date2026082800…`,
comma-separated like `issue_reasons`; cleared on retry). `BookingService::applyOne`
returns a **`RelatedBooking`** DTO (id + description) instead of a bare string, so
the relationship survives as data rather than only inside the sentence
`describeRelated` writes into `messages.error`. The sidebar's message panel shows
it under **"Relates to"**, kept separate from "Bookings from this email" — *made
this* and *is about this* are different claims and merging them would misreport
what the app did. No backfill: the old rows' ids are recoverable only by parsing
prose, and re-running the message fills the column properly.

## Navigation counters

**"What awaits you, over how much there is"** — `NavCounter.vue`, one per row
(2026-09-17). The lozenge (`NcCounterBubble`, highlighted) is the outstanding
work; the total is muted text beside it. Reasons, in the order they bind:
- **Only one of the two numbers is a call to action**, so only one gets the
  emphasis a bubble carries. Mail's nav is the precedent: a lozenge there means
  unread, never "how many mails exist". A muted number then means the same thing
  on every row, which is what lets Trips show one on its own.
- **Trips shows a total alone.** Travel you have filed carries no task, and the
  candidates for a trip "queue" were all worse than nothing: current/future is
  *what is live*, not what needs doing, and trips-holding-drafts counts the same
  drafts the Bookings row already counts. A lozenge that means two different
  things by row is worse than an asymmetric nav.
- **Zero outstanding renders as the total alone** — no `0` bubble, and no bare
  "/ 14" with nothing in front of the slash. A row with nothing to do should look
  like one.
- **The pair is labelled as a sentence** (`label`, the tooltip *and* the
  accessible name): "3 of 14 bookings await review". Two numbers either side of a
  slash read as "3 14" aloud — the slash is punctuation nobody announces — so the
  numbers themselves are `aria-hidden` and the sentence is what is read.
- `X` is drafts for Bookings (`draftCount`) and failed/dropped for Messages
  (`needsAttention`). **Messages' total is the list page, not the ledger**:
  `ProcessedMessageMapper::findAllForUser` caps at 200, so `Y` silently becomes
  "of the most recent 200" once the ledger is longer. Bookings and Trips are
  uncapped and exact.

## Heading beside the navigation toggle

- **The heading sits beside the navigation toggle** below 620px of *viewport*
  (a media query, since the toggle follows the viewport). `.tm-content`'s top
  padding matches the toggle's offset, the toolbar is one clickable area tall,
  and only its first item is indented. A wrapped second line starts at the
  content edge.
- **The drawer closes after picking a view on mobile** (`showView` in
  `App.vue`). `NcAppNavigationItem` does this only for router links, and
  `NcAppNavigation` 9.14 has no controlled `open` prop, so it emits
  `toggle-navigation` on `@nextcloud/event-bus`, which is a direct dependency.

## Refresh

- **One `RefreshButton.vue`, two homes, switched on `useIsMobile()`**: the
  navigation's `#search` slot on a wide window (Mail's spot), each view's toolbar
  on a phone, where the drawer is closed. It calls `reload()` and spins on the
  store's shared `loading`. Decision: "Refresh re-reads, and sits in the
  navigation" in `docs/decisions.md`.
- **Toolbar order: display controls, then actions.** Lists: sort menu, Refresh,
  then Trips' "New trip". Calendar: the archive toggle, then Refresh. In a grid
  toolbar whichever of sort menu and Refresh is first on screen takes the auto
  margin (`grid.css`): two auto margins split the free space and strand one
  button mid-row. On the calendar they share `.tm-cal-end`, so the toolbar keeps
  exactly two ends.
