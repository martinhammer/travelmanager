import { computed, ref, watch } from 'vue'
import type { Booking, Message, Trip } from './api'
import { findMessageBySourceId } from './api'
import type { DetailType, Route } from './detail'
import { byId, detailLabel, detailRoute, formatRoute, keepsView, matchRoute, parseRoute } from './detail'
import { bookings, messages, trips } from './store'

/**
 * What is open, and how to move between things.
 *
 * The route lives in `location.hash` so the detail panel is linkable and
 * bookmarkable, and so the browser's own Back button walks the trail. Kept in a
 * module rather than in App.vue because every view needs to open a detail and
 * to know whether its row is the open one — and a future calendar will too.
 *
 * The *parsing* is pure and tested in src/detail.ts; this file only holds the
 * reactive state and the browser plumbing around it.
 */

export const route = ref<Route>(parseRoute(window.location.hash))

/** Caption for the panel's back button, or null when there is nowhere to go back to. */
export const backLabel = ref<string | null>(null)

export const view = computed({
	get: () => route.value.view,
	// Switching view closes the panel: a booking id means nothing under Messages.
	set: (value: Route['view']) => navigate({ view: value, detail: null }),
})

/**
 * Go somewhere, pushing a history entry so Back returns here. `fromLabel` rides
 * along in history.state rather than in a ref of our own, so the back button's
 * caption survives the browser's own Back and Forward.
 * @param next where to go
 * @param fromLabel what to call the place being left, if it should be offered
 */
export const navigate = (next: Route, fromLabel: string | null = null): void => {
	route.value = next
	const hash = formatRoute(next)
	if (hash !== window.location.hash) {
		window.history.pushState({ fromLabel }, '', hash)
	}
}

// The view to stay on while a detail is open, or null to hand over to the
// entity's own list. Only the calendar holds its ground, and not for messages —
// see keepsView.
const within = (type: DetailType): Route['view'] | null =>
	keepsView(route.value.view, type) ? route.value.view : null

const entitiesFor = (type: DetailType): (Booking | Trip | Message)[] => {
	switch (type) {
	case 'booking':
		return bookings.value
	case 'trip':
		return trips.value
	case 'message':
		return messages.value
	}
}

/**
 * Open one thing in the detail panel **from a row or a calendar bar**, discarding
 * any trail.
 *
 * Picking another row is not navigating *from* whatever the panel happened to be
 * showing — offering "← Trip 1" after clicking Trip 2 in the list describes a
 * journey the user did not take. Browser Back still returns to the previous
 * panel; it is the panel's own back affordance that has to reset.
 * @param type the kind of entity
 * @param id its id
 */
export const openDetail = (type: DetailType, id: number): void => {
	navigate(detailRoute(type, id, within(type)))
	backLabel.value = null
}

/**
 * Open one thing **from inside the panel**, following a cross-link, keeping a
 * way back to where the link was followed from.
 * @param type the kind of entity
 * @param id its id
 */
export const openLinked = (type: DetailType, id: number): void => {
	// Name the place being left, so the panel can offer a way back to it.
	const current = route.value.detail
	const item = current === null ? null : byId(entitiesFor(current.type), current.id)
	const from = current === null || item === null ? null : detailLabel(current.type, item)
	navigate(detailRoute(type, id, within(type)), from)
	backLabel.value = from
}

/**
 * The address a detail opens at, for controls that are genuinely links rather
 * than buttons — the calendar's bars.
 *
 * Shares `within()` with openDetail deliberately: an href and the click handler
 * beside it must never describe different destinations, and they would drift the
 * moment one of them grew a special case.
 * @param type the kind of entity
 * @param id its id
 */
export const detailHref = (type: DetailType, id: number): string =>
	formatRoute(detailRoute(type, id, within(type)))

export const closeDetail = (): void => {
	navigate({ view: route.value.view, detail: null })
	backLabel.value = null
}

// Delegated to the browser so our trail and its history are the same thing —
// popstate then restores both the route and the caption.
export const goBack = (): void => window.history.back()

/**
 * Whether this row is the one the sidebar is showing — so the panel is visibly
 * anchored to a row rather than floating free of the list.
 * @param type the kind of entity the row holds
 * @param id the row's id
 */
export const isOpen = (type: DetailType, id: number): boolean =>
	route.value.detail?.type === type && route.value.detail.id === id

/**
 * A booking's source email may predate the message list's page (it caps at 200),
 * in which case the trail back would silently vanish. Fetch that one message and
 * fold it into the loaded set.
 */
export const ensureSourceMessage = async (): Promise<void> => {
	const detail = route.value.detail
	if (detail?.type !== 'booking') {
		return
	}
	const booking = byId(bookings.value, detail.id)
	const sourceId = booking?.sourceMessageId
	if (!sourceId || messages.value.some((m) => m.messageId === sourceId)) {
		return
	}
	try {
		const found = await findMessageBySourceId(sourceId)
		if (found !== null) {
			messages.value = [...messages.value, found]
		}
	} catch (e) {
		// Best-effort: the panel simply does not offer the source link.
	}
}

watch(() => route.value.detail, ensureSourceMessage)

// The browser's Back and Forward move the route, and carry the back button's
// caption with them — history.state survives both, a ref would not.
const onPopState = (): void => {
	const next = matchRoute(window.location.hash)
	if (next === null) {
		// Not one of ours: put our own address back rather than following it.
		// Nextcloud's nav items are <a href="#">, so a stray '#' arrives on every
		// navigation click; following it would snap the view back to Bookings.
		window.history.replaceState(window.history.state, '', formatRoute(route.value))
		return
	}
	route.value = next
	backLabel.value = (window.history.state as { fromLabel?: string | null } | null)?.fromLabel ?? null
}

/** Start listening for history events; returns the teardown. */
export const startNavigation = (): (() => void) => {
	// Stamp the initial entry so history.state is never null on the way back,
	// and normalise a bare or malformed hash to the route we actually rendered.
	window.history.replaceState({ fromLabel: null }, '', formatRoute(route.value))
	window.addEventListener('popstate', onPopState)
	return () => window.removeEventListener('popstate', onPopState)
}
