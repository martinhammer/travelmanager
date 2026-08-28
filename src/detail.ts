import type { Booking, Message, Trip } from './api'

/**
 * Addressing and cross-entity lookups for the detail sidebar.
 *
 * The panel is openable from anywhere — a message row, a booking row, a trip,
 * and later a calendar — so *what is open* has to be a value, not a component's
 * internal state. It lives in `location.hash`, which makes it linkable and
 * bookmarkable and lets the browser's own Back button walk the trail.
 *
 * Pure and free of @nextcloud/* imports so it stays unit-testable (§7).
 */

/** The kinds of thing the sidebar can show. */
export type DetailType = 'booking' | 'trip' | 'message'

/** What is currently open: a view, and optionally one entity within it. */
export interface Route {
	view: 'bookings' | 'trips' | 'messages'
	detail: { type: DetailType, id: number } | null
}

// The URL segment for each view, and the entity type it shows.
const VIEWS: Record<string, { view: Route['view'], type: DetailType }> = {
	bookings: { view: 'bookings', type: 'booking' },
	trips: { view: 'trips', type: 'trip' },
	messages: { view: 'messages', type: 'message' },
}

const SEGMENTS: Record<DetailType, string> = {
	booking: 'bookings',
	trip: 'trips',
	message: 'messages',
}

/** Where the app starts when the URL says nothing. */
export const DEFAULT_ROUTE: Route = { view: 'bookings', detail: null }

/**
 * Parse a location hash into a route, or null when it names no view of ours.
 *
 * The null case matters: a bare '#' is not "go to the default view", it is noise
 * — Nextcloud's own nav items are `<a href="#">`, so clicking one appends a stray
 * hash entry. Treating that as a route would teleport the user back to Bookings
 * every time they used the navigation.
 * @param hash the raw location.hash, with or without its leading '#'
 */
export const matchRoute = (hash: string): Route | null => {
	const parts = hash.replace(/^#\/?/, '').split('/').filter((part) => part !== '')
	const entry = VIEWS[parts[0] ?? '']
	if (entry === undefined) {
		return null
	}
	const id = Number(parts[1])
	// Number('') is 0 and Number('abc') is NaN; neither is a real id.
	if (parts[1] === undefined || !Number.isInteger(id) || id <= 0) {
		return { view: entry.view, detail: null }
	}
	return { view: entry.view, detail: { type: entry.type, id } }
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
 * written hash with the current one is a plain string comparison.
 * @param route the route to serialise
 */
export const formatRoute = (route: Route): string =>
	route.detail === null
		? `#/${route.view}`
		: `#/${SEGMENTS[route.detail.type]}/${route.detail.id}`

/**
 * The route that shows one entity, including the view it belongs to — so opening
 * a booking from a message also switches the list underneath to Bookings, and
 * closing the panel leaves you somewhere coherent.
 * @param type the kind of entity
 * @param id its id
 */
export const detailRoute = (type: DetailType, id: number): Route => ({
	view: VIEWS[SEGMENTS[type]].view,
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
 * The bookings an email matched but did not change — the `related` case. Held as
 * ids on the message rather than derived, because nothing links the two: this
 * email did *not* create these bookings, an earlier one did.
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
