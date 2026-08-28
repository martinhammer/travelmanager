import { t } from '@nextcloud/l10n'
import type { Booking, ReviewState, Trip } from './api'
import { bookingSpan, decodeHtmlEntities } from './bookings'
import { formatSpan } from './grid'
import { allTripRows } from './store'

/**
 * How the domain is worded, in one place.
 *
 * Unlike bookings.ts / trips.ts / messages.ts this module *does* import
 * `@nextcloud/l10n`, so it is not unit-testable standalone (§7) — that is the
 * trade for having every view say the same thing. Keep logic out of it: anything
 * with a decision in it belongs in a pure module and gets tested there.
 */

/**
 * Singular, and used for the Type column and the type filter chips alike.
 * @param type the booking type slug
 */
export const typeName = (type: string): string => {
	switch (type) {
	case 'flight':
		return t('travelmanager', 'Flight')
	case 'accommodation':
		return t('travelmanager', 'Accommodation')
	case 'car_rental':
		return t('travelmanager', 'Car rental')
	default:
		return type
	}
}

const reviewStateLabels: Record<string, string> = {
	draft: t('travelmanager', 'Draft'),
	confirmed: t('travelmanager', 'Confirmed'),
	discarded: t('travelmanager', 'Discarded'),
	archived: t('travelmanager', 'Archived'),
}

export const reviewStateLabel = (state: string): string => reviewStateLabels[state] ?? state

// The other state axis: a fact about the booking, set from the email.
const bookingStatusLabels: Record<string, string> = {
	active: t('travelmanager', 'Active'),
	cancelled: t('travelmanager', 'Cancelled'),
	superseded: t('travelmanager', 'Superseded'),
}

export const bookingStatusLabel = (status: string): string => bookingStatusLabels[status] ?? status

/**
 * Button label + success toast per target review state. Keeping them together
 * means adding a state is a single edit here plus one in reviewActions().
 */
export const reviewLabels: Record<ReviewState, { action: string, done: string }> = {
	draft: { action: t('travelmanager', 'Restore'), done: t('travelmanager', 'Booking restored to drafts') },
	confirmed: { action: t('travelmanager', 'Confirm'), done: t('travelmanager', 'Booking confirmed') },
	discarded: { action: t('travelmanager', 'Discard'), done: t('travelmanager', 'Booking discarded') },
	archived: { action: t('travelmanager', 'Archive'), done: t('travelmanager', 'Booking archived') },
}

/**
 * A restore back to 'confirmed' is an undo, not a fresh confirmation.
 * @param item the booking being acted on
 * @param target the review state the button moves it to
 */
export const actionLabel = (item: Booking, target: ReviewState): string =>
	target === 'confirmed' && item.reviewState !== 'draft'
		? t('travelmanager', 'Restore')
		: reviewLabels[target].action

/**
 * Decoded so a leftover entity reads as a character, not as literal markup.
 * @param item the booking to name
 */
export const bookingLabel = (item: Booking): string => decodeHtmlEntities(item.title || item.type)

export const tripLabel = (trip: Trip): string => decodeHtmlEntities(trip.name)

/**
 * One-line description of a booking for the places it appears as a list item
 * rather than a grid row (a trip's contents, the link dialog). 'active' is left
 * out: it is the ordinary case and saying so on every row is noise — only a
 * cancellation or supersession is worth the reader's attention.
 * @param item the booking to describe
 */
export const bookingMeta = (item: Booking): string => [
	typeName(item.type),
	reviewStateLabel(item.reviewState),
	item.status === 'active' ? null : bookingStatusLabel(item.status),
	bookingSpan(item) || null,
].filter(Boolean).join(' · ')

/**
 * A trip's dates and size, for choosing between trips in a dialog.
 * @param tripId the trip to describe
 */
export const tripSummary = (tripId: number): string => {
	const row = allTripRows.value.find((r) => r.trip.id === tripId)
	if (row === undefined) {
		return ''
	}
	return [
		formatSpan(row.start, row.end) || null,
		t('travelmanager', '{n} booking(s)', { n: row.bookings.length }),
	].filter(Boolean).join(' · ')
}
