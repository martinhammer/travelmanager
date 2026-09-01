import type { Booking, Message, Trip } from './api'

/**
 * Addressing and cross-entity lookups for the detail sidebar.
 *
 * The panel is openable from anywhere — a message row, a booking row, a trip,
 * a calendar bar — so *what is open* has to be a value, not a component's
 * internal state. It lives in `location.hash`, which makes it linkable and
 * bookmarkable and lets the browser's own Back button walk the trail.
 *
 * Pure and free of @nextcloud/* imports so it stays unit-testable (§7).
 */

/** The kinds of thing the sidebar can show. */
export type DetailType = 'booking' | 'trip' | 'message'

/** The app's views. The calendar is an overview: it has no entity type of its own. */
export type ViewName = 'calendar' | 'bookings' | 'trips' | 'messages'

/** What is currently open: a view, and optionally one entity shown over it. */
export interface Route {
	view: ViewName
	detail: { type: DetailType, id: number } | null
}

// The URL segment for each view, and the entity type its rows hold.
const VIEWS: Record<string, { view: ViewName, type: DetailType | null }> = {
	calendar: { view: 'calendar', type: null },
	bookings: { view: 'bookings', type: 'booking' },
	trips: { view: 'trips', type: 'trip' },
	messages: { view: 'messages', type: 'message' },
}

const SEGMENTS: Record<DetailType, string> = {
	booking: 'bookings',
	trip: 'trips',
	message: 'messages',
}

/** Where the app starts when the URL says nothing: the overview, not a list. */
export const DEFAULT_ROUTE: Route = { view: 'calendar', detail: null }

// A path segment as an entity id, or null when it is not one. Number('') is 0
// and Number('abc') is NaN; neither is a real id.
const toId = (value: string): number | null => {
	const id = Number(value)
	return Number.isInteger(id) && id > 0 ? id : null
}

/**
 * Whether opening a detail should leave this view showing, rather than handing
 * over to the entity's own list.
 *
 * True for the calendar, and that is the whole reason the three-segment hash
 * exists: the month is the thing you are working *from*, so clicking a bar must
 * not take the grid off screen. The lists are each about one kind of thing, so
 * opening a different kind there genuinely means you have left.
 *
 * **Except for messages.** A message is not on the calendar and never will be —
 * it is an email, not something that happens on a day — so holding the month
 * behind one shows a grid with no bearing on what the panel is describing. The
 * Messages list can also *expand* the row, which is where the prompt and the raw
 * model response live; the panel only summarises them.
 * @param view the view currently showing
 * @param type the kind of entity being opened
 */
export const keepsView = (view: ViewName, type: DetailType): boolean =>
	view === 'calendar' && type !== 'message'

/**
 * Parse a location hash into a route, or null when it names no view of ours.
 *
 * Two grammars, because a detail is not always shown over its own list:
 * - `#/bookings/42` — the shorthand, a view showing one of its own rows.
 * - `#/calendar/bookings/42` — a view with something else open over it.
 *
 * The null case matters: a bare '#' is not "go to the default view", it is noise
 * — Nextcloud's own nav items are `<a href="#">`, so clicking one appends a stray
 * hash entry. Treating that as a route would teleport the user out of whatever
 * they were looking at every time they used the navigation.
 * @param hash the raw location.hash, with or without its leading '#'
 */
export const matchRoute = (hash: string): Route | null => {
	const parts = hash.replace(/^#\/?/, '').split('/').filter((part) => part !== '')
	const entry = VIEWS[parts[0] ?? '']
	if (entry === undefined) {
		return null
	}
	const view = entry.view

	if (parts.length >= 3) {
		const type = VIEWS[parts[1] ?? '']?.type ?? null
		const id = toId(parts[2] ?? '')
		return type !== null && id !== null
			? { view, detail: { type, id } }
			: { view, detail: null }
	}

	// The shorthand only means anything for a view that has rows of its own, so
	// '#/calendar/42' is a calendar with nothing open rather than a broken id.
	const id = parts.length === 2 ? toId(parts[1] ?? '') : null
	return id !== null && entry.type !== null
		? { view, detail: { type: entry.type, id } }
		: { view, detail: null }
}

/**
 * As matchRoute, but falling back to the default — a stale or hand-edited URL
 * should land you somewhere usable rather than on a blank screen. Use this on
 * first load; use matchRoute when reacting to history events, where "not a
 * route of ours" has to stay distinguishable.
 * @param hash the raw location.hash, with or without its leading '#'
 */
export const parseRoute = (hash: string): Route => matchRoute(hash) ?? DEFAULT_ROUTE

/**
 * The hash for a route. Always absolute and leading-slashed, so comparing the
 * written hash with the current one is a plain string comparison. Collapses to
 * the two-segment shorthand whenever the entity is shown over its own list, so
 * the URLs people already have keep working and keep being generated.
 * @param route the route to serialise
 */
export const formatRoute = (route: Route): string => {
	if (route.detail === null) {
		return `#/${route.view}`
	}
	const segment = SEGMENTS[route.detail.type]
	return segment === route.view
		? `#/${segment}/${route.detail.id}`
		: `#/${route.view}/${segment}/${route.detail.id}`
}

/**
 * The route that shows one entity.
 *
 * By default it also switches to the entity's own view, so opening a booking
 * from a message leaves the list underneath coherent with the panel. Pass
 * `within` to keep a view showing instead — see keepsView.
 * @param type the kind of entity
 * @param id its id
 * @param within the view to stay on, or null for the entity's own
 */
export const detailRoute = (type: DetailType, id: number, within: ViewName | null = null): Route => ({
	view: within ?? VIEWS[SEGMENTS[type]].view,
	detail: { type, id },
})

/**
 * The bookings an email produced. Matched on the RFC Message-ID rather than a
 * foreign key because that is what the extraction records; one email can yield
 * several bookings (a flight *and* a hotel), hence a list.
 * @param bookings every booking
 * @param message the message to trace forward from
 */
export const bookingsFromMessage = (bookings: Booking[], message: Message): Booking[] =>
	bookings.filter((booking) => booking.sourceMessageId === message.messageId)

/**
 * The bookings an email is about but did not create, because they already
 * existed — the `related` status. Held as ids on the message rather than
 * derived, because nothing links the two: an earlier email created these
 * bookings, not this one. Possible duplicates are deliberately *not* here; that
 * is a booking-to-booking relation, see `possibleDuplicates` in bookings.ts.
 * @param bookings every booking
 * @param message the message to trace sideways from
 */
export const relatedBookings = (bookings: Booking[], message: Message): Booking[] =>
	message.relatedBookingIds
		.map((id) => bookings.find((booking) => booking.id === id))
		// A related booking can have been permanently deleted since.
		.filter((booking): booking is Booking => booking !== undefined)

/**
 * The already-loaded message that created a booking, if it is in the list. The
 * list caps at 200 rows, so a null here means "not loaded", not "does not
 * exist" — the caller falls back to findMessageBySourceId.
 * @param messages the loaded messages
 * @param booking the booking to trace back from
 */
export const messageForBooking = (messages: Message[], booking: Booking): Message | null => {
	if (booking.sourceMessageId === null) {
		return null
	}
	return messages.find((message) => message.messageId === booking.sourceMessageId) ?? null
}

/**
 * Find an entity by id, for rendering whatever the route points at.
 * @param items the loaded entities
 * @param id the id to find
 */
export const byId = <T extends { id: number }>(items: T[], id: number): T | null =>
	items.find((item) => item.id === id) ?? null

/**
 * A short label for a detail target, used on the sidebar's back button ("← Fw:
 * Your booking"). Kept here so the label and the route are produced from the
 * same place.
 * @param type the kind of entity
 * @param item the entity itself
 */
export const detailLabel = (type: DetailType, item: Booking | Trip | Message): string => {
	switch (type) {
	case 'booking':
		return (item as Booking).title || (item as Booking).type
	case 'trip':
		return (item as Trip).name
	case 'message':
		return (item as Message).subject || (item as Message).messageId
	}
}
