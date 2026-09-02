import type { SortColumn, SortDirection } from './grid'
import { formatSpan, localDate } from './grid'
import type {
	Booking,
	CarDetails,
	FlightDetails,
	FlightSegment,
	HotelDetails,
	ReviewState,
	WhenWhere,
} from './api'

// Common named entities plus numeric/hex refs (&#39; &#x27; …). Source emails
// occasionally ship a plain-text part that was naively tag-stripped upstream
// without decoding entities, so titles/names extracted from it can carry
// literal "&#39;" etc. through to booking titles and (via copy/paste or
// pre-filled trip names) trip names. Decode defensively wherever such text is
// displayed, so a leftover entity reads as a normal character instead of
// literal markup.
const NAMED_ENTITIES: Record<string, string> = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: '\'',
	nbsp: ' ',
	mdash: '—',
	ndash: '–',
	hellip: '…',
}

const decodeOnce = (value: string): string => {
	return value.replace(/&(#\d+|#x[0-9a-f]+|[a-z]+);/gi, (match, ref: string): string => {
		if (ref[0] === '#') {
			const codePoint = ref[1]?.toLowerCase() === 'x' ? parseInt(ref.slice(2), 16) : parseInt(ref.slice(1), 10)
			return Number.isNaN(codePoint) ? match : String.fromCodePoint(codePoint)
		}
		const decoded = NAMED_ENTITIES[ref.toLowerCase()]
		return decoded ?? match
	})
}

/**
 * Decode common HTML entities in plain text pulled from email content.
 * Some source emails are double-encoded (e.g. "&amp;#39;"): a single pass
 * only turns that into "&#39;", since the newly-formed entity isn't
 * re-scanned within the same replace(). Loop until stable (capped) to fully
 * resolve those.
 * @param value the raw text, possibly containing HTML entities
 */
export const decodeHtmlEntities = (value: string): string => {
	let decoded = value
	for (let i = 0; i < 3; i++) {
		const next = decodeOnce(decoded)
		if (next === decoded) {
			break
		}
		decoded = next
	}
	return decoded
}

/** A single labelled field ready to render as "Label: value". */
export interface SegmentField {
	label: string
	value: string
}

/**
 * Format an ISO-ish local wall-clock string for display.
 * Turns "2026-08-28T17:30:00" into "2026-08-28 17:30"; a date-only midnight
 * ("2026-08-28T00:00:00") renders as just the date. Leaves anything
 * unrecognised untouched. No timezone conversion is performed (V8).
 * @param value the stored local string, or null
 */
export const formatDateTime = (value: string | null | undefined): string => {
	if (!value) {
		return ''
	}
	const match = value.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/)
	if (!match) {
		return value
	}
	return match[2] === '00:00' ? match[1] : `${match[1]} ${match[2]}`
}

// Format a local instant + optional timezone as "2026-08-28 17:30 (Europe/Oslo)".
const formatWhen = (when: WhenWhere | undefined): string => {
	const time = formatDateTime(when?.local)
	if (!time) {
		return ''
	}
	return when?.timezone ? `${time} (${when.timezone})` : time
}

// Push a labelled field only when it carries a value.
const collect = (fields: SegmentField[], label: string, value: string | null | undefined): void => {
	if (value !== null && value !== undefined && value !== '') {
		fields.push({ label, value })
	}
}

/**
 * The booking's travel span for the grid's Travel dates column.
 * @param booking the booking to describe
 */
export const bookingSpan = (booking: Booking): string =>
	formatSpan(booking.startDate, booking.endDate)

/**
 * Labelled fields for one flight leg. Only populated fields are returned so a
 * sparse leg doesn't render empty rows.
 * @param seg the flight segment
 */
export const flightSegmentFields = (seg: FlightSegment): SegmentField[] => {
	const fields: SegmentField[] = []
	const flight = [seg.carrier, seg.flightNumber].filter(Boolean).join(' ')
	collect(fields, 'Flight', flight || null)
	collect(fields, 'Operated by', seg.operatingCarrier)
	collect(fields, 'Origin', seg.origin)
	collect(fields, 'Destination', seg.destination)
	collect(fields, 'Departure', formatWhen({ local: seg.departureLocal, timezone: seg.departureTimezone }))
	collect(fields, 'Arrival', formatWhen({ local: seg.arrivalLocal, timezone: seg.arrivalTimezone }))
	collect(fields, 'Cabin', seg.cabinClass)
	collect(fields, 'Seat', seg.seat)
	collect(fields, 'Terminal', seg.terminal)
	collect(fields, 'Gate', seg.gate)
	return fields
}

/**
 * Labelled fields describing a car rental (supplier, car, pickup/dropoff, driver).
 * @param details the car-rental details
 */
export const carFields = (details: CarDetails): SegmentField[] => {
	const fields: SegmentField[] = []
	collect(fields, 'Supplier', details.supplier)
	collect(fields, 'Rental company', details.rentalCompany)
	collect(fields, 'Car type', details.carType)
	collect(fields, 'Features', (details.carFeatures ?? []).join(', ') || null)
	collect(fields, 'Driver', details.driver?.name)
	collect(fields, 'Pick-up', details.pickup?.location)
	collect(fields, 'Pick-up time', formatWhen(details.pickup))
	collect(fields, 'Drop-off', details.dropoff?.location)
	collect(fields, 'Drop-off time', formatWhen(details.dropoff))
	return fields
}

/**
 * Labelled fields describing an accommodation stay.
 * @param details the accommodation details
 */
export const hotelFields = (details: HotelDetails): SegmentField[] => {
	const fields: SegmentField[] = []
	collect(fields, 'Property', details.propertyName)
	collect(fields, 'Address', details.address)
	collect(fields, 'Check-in', formatWhen(details.checkIn))
	collect(fields, 'Check-out', formatWhen(details.checkOut))
	collect(fields, 'Room', details.roomType)
	collect(fields, 'Board', details.board)
	collect(fields, 'Rooms', details.numberOfRooms != null ? String(details.numberOfRooms) : null)
	const guests = (details.guests ?? []).map((g) => g.name).filter(Boolean).join(', ')
	collect(fields, 'Guests', guests || null)
	return fields
}

/**
 * Passenger summary lines for a flight (name + frequent flyer + baggage).
 * @param details the flight details
 */
export const passengerLines = (details: FlightDetails): string[] =>
	(details.passengers ?? [])
		.map((p) => {
			const extras = [
				p.frequentFlyer ? `FF ${p.frequentFlyer}` : null,
				p.baggage ? `bag ${p.baggage}` : null,
			].filter(Boolean).join(', ')
			const name = p.name ?? ''
			return extras ? `${name} (${extras})` : name
		})
		.filter((line) => line.trim() !== '')

/**
 * Filter bookings by review state. The sentinel 'all' returns everything,
 * including discarded and archived bookings — those are soft states, so the
 * rows survive and stay visible rather than disappearing.
 * Pure and free of Nextcloud imports so it is unit-testable in isolation.
 * @param items the bookings to filter
 * @param reviewState the review state to keep, or 'all' for no filtering
 */
export const filterByReviewState = (items: Booking[], reviewState: string): Booking[] => {
	// 'duplicates' is not a review state, and sits in the same chip row anyway:
	// the chips are one question — "which bookings am I looking at?" — and the
	// answer people want most often after "the drafts" is "the ones I have to
	// settle". Kept here rather than as a second filter ref so the grid keeps one
	// selection, not two that can contradict each other.
	if (reviewState === 'duplicates') {
		return items.filter((item) => hasPossibleDuplicate(items, item))
	}
	return reviewState === 'all' ? items : items.filter((item) => item.reviewState === reviewState)
}

/** The Bookings grid's sortable columns — one per column heading. */
export type BookingSort = 'title' | 'trip' | 'type' | 'provider' | 'reference' | 'travel' | 'added' | 'reviewState'

/** Trip names by id, for the columns that show or sort on a booking's trip. */
export type TripNames = Record<number, string>

/**
 * The Bookings grid's columns, in display order. Same contract as
 * MESSAGE_COLUMNS: labels live in the component so `t()` sees literal strings
 * and this module stays free of @nextcloud/* imports (§7 of CLAUDE.md).
 *
 * 'travel' is the default, and the only column with an ordering specific to
 * travel rather than to text or time — see sortBookings.
 */
export const BOOKING_COLUMNS: SortColumn<BookingSort>[] = [
	{ key: 'title', defaultDirection: 'asc' },
	{ key: 'trip', defaultDirection: 'asc' },
	{ key: 'type', defaultDirection: 'asc' },
	{ key: 'provider', defaultDirection: 'asc' },
	{ key: 'reference', defaultDirection: 'asc' },
	{ key: 'travel', defaultDirection: 'asc' },
	{ key: 'added', defaultDirection: 'desc' },
	{ key: 'reviewState', defaultDirection: 'asc' },
]

// Sorts a nullable ISO-ish string descending, with nulls always last.
const byDateDesc = (a: string | null, b: string | null): number => {
	if (a === b) {
		return 0
	}
	if (!a) {
		return 1
	}
	if (!b) {
		return -1
	}
	return a < b ? 1 : -1
}

// The value a column sorts on; strings lowercased so case never splits a group.
const sortValue = (item: Booking, sort: BookingSort, tripNames: TripNames): string | null => {
	switch (sort) {
	case 'title':
		return item.title?.toLowerCase() || null
	case 'trip':
		// Sorts on the name shown, not the id: an unlinked booking has no value
		// at all and sinks, rather than sorting as "trip zero".
		return item.tripId === null ? null : (tripNames[item.tripId]?.toLowerCase() || null)
	case 'type':
		return item.type.toLowerCase()
	case 'provider':
		return item.provider?.toLowerCase() || null
	case 'reference':
		return item.bookingReference?.toLowerCase() || null
	case 'travel':
		return item.startDate
	case 'added':
		return item.createdAt
	case 'reviewState':
		return item.reviewState
	}
}

/**
 * Order bookings by one column, valueless rows last in both directions.
 *
 * 'travel' ascending is special-cased and is the view's default: the next trip
 * first, then further-off ones, with **past travel below in reverse order** and
 * undated bookings last. Plain ascending by date would bury what is coming up
 * under everything that already happened — which is the whole reason this view
 * does not simply sort the column like any other. Descending is a plain reverse
 * chronology, for when you are looking back rather than ahead.
 * @param items the bookings to order (not mutated)
 * @param sort the column to order by
 * @param direction 'asc' or 'desc'
 * @param now reference point for "past"; injectable so tests do not depend on today
 * @param tripNames trip names by id, needed only by the 'trip' column
 */
export const sortBookings = (
	items: Booking[],
	sort: BookingSort,
	direction: SortDirection = 'asc',
	now: Date = new Date(),
	tripNames: TripNames = {},
): Booking[] => {
	const copy = [...items]

	if (sort === 'travel' && direction === 'asc') {
		// By calendar date, matching tripPeriod: anything travelling today counts
		// as upcoming, not past, however late in the day it is read.
		const today = localDate(now)
		const rank = (item: Booking): number => {
			if (!item.startDate) {
				return 2
			}
			return item.startDate.slice(0, 10) >= today ? 0 : 1
		}
		return copy.sort((a, b) => {
			const ra = rank(a)
			const rb = rank(b)
			if (ra !== rb) {
				return ra - rb
			}
			if (ra === 2) {
				return byDateDesc(a.createdAt, b.createdAt)
			}
			// Upcoming ascending (soonest first); past descending (most recent first).
			return ra === 0
				? (a.startDate! < b.startDate! ? -1 : 1)
				: byDateDesc(a.startDate, b.startDate)
		})
	}

	const sign = direction === 'asc' ? 1 : -1
	return copy.sort((a, b) => {
		const av = sortValue(a, sort, tripNames)
		const bv = sortValue(b, sort, tripNames)
		if (av === bv) {
			return 0
		}
		if (av === null) {
			return 1
		}
		if (bv === null) {
			return -1
		}
		return av < bv ? -sign : sign
	})
}

/**
 * The booking types actually present in a set, so the type filter only offers
 * values that would return something.
 * @param items the bookings to inspect
 */
export const bookingTypes = (items: Booking[]): string[] =>
	[...new Set(items.map((item) => item.type))].sort()

/**
 * Apply the Bookings view's filters. Both accept the sentinel 'all'.
 * @param items the bookings to filter
 * @param reviewState review state to keep, or 'all'
 * @param type booking type to keep, or 'all'
 */
export const filterBookings = (items: Booking[], reviewState: string, type: string): Booking[] =>
	filterByReviewState(items, reviewState).filter((item) => type === 'all' || item.type === type)

/**
 * Number of bookings still awaiting confirmation.
 * @param items the bookings to count drafts in
 */
export const draftCount = (items: Booking[]): number =>
	items.filter((item) => item.reviewState === 'draft').length

/**
 * Where "Restore" puts a booking, decided by what it is being restored *from*
 * rather than by where it had been before.
 *
 * - **Discarded → draft, always**, even if it was confirmed when you discarded
 *   it. Discarding is how you say "this is wrong"; taking that back means the
 *   booking is worth another look, not that it is right. Draft is the state that
 *   means "needs review", so that is where the second look starts — and
 *   confirming from there is one click, with the trip picker attached.
 * - **Archived → confirmed.** Archiving is not rejection, it is completion: the
 *   travel happened and you are done with it. Un-archiving should not demand
 *   that you vouch for the booking a second time. Archive is only reachable from
 *   confirmed (see reviewActions), so there is no other state to return to.
 *
 * This is also what keeps trip links coherent: an archived booking keeps its
 * trip, and restoring puts it back in the one state where being linked is legal.
 * A discarded booking has already been unlinked server-side, so it comes back a
 * plain unfiled draft.
 *
 * Branches on `reviewState`, not on `confirmedAt` as it once did — that
 * timestamp is still recorded on a first confirmation, but nothing reads it now.
 * @param item the booking being restored
 */
export const restoreTarget = (item: Booking): ReviewState =>
	item.reviewState === 'archived' ? 'confirmed' : 'draft'

/**
 * The review states a booking can be moved to from where it is now, in the
 * order they should be offered. Drives the per-card action buttons.
 * @param item the booking to offer actions for
 */
export const reviewActions = (item: Booking): ReviewState[] => {
	switch (item.reviewState) {
	case 'draft':
		return ['confirmed', 'discarded']
	case 'confirmed':
		return ['archived', 'discarded']
	// Discarded and archived are undo-able; the only way out is back.
	default:
		return [restoreTarget(item)]
	}
}

/**
 * The bookings linked to a given trip.
 * @param items the bookings to filter
 * @param tripId the trip to collect members for
 */
export const bookingsForTrip = (items: Booking[], tripId: number): Booking[] =>
	items.filter((item) => item.tripId === tripId)

/**
 * The bookings not yet linked to any trip — the pool eligible to be linked.
 * Confirmed only: see linkDialogBookings for why.
 * @param items the bookings to filter
 */
export const unassignedBookings = (items: Booking[]): Booking[] =>
	items.filter((item) => item.tripId === null && item.reviewState === 'confirmed')

/**
 * The bookings shown in the "Link a booking" dialog for a given trip: those
 * already linked to it (so they stay visible with an Unlink action instead of
 * disappearing once linked) plus the still-unassigned **confirmed** pool.
 *
 * Only confirmed bookings can be linked — a trip groups travel you have decided
 * is real, and a draft is an extraction nobody has vouched for yet, so filing
 * one would feed unreviewed guesses into the trip's derived dates and type
 * lozenges. Drafts reach a trip through the picker attached to *confirming*
 * them, which is the point at which they stop being guesses.
 *
 * Already-linked bookings are kept whatever their state, so one that was linked
 * and later restored to draft can still be unlinked here rather than being
 * stranded. The backend enforces the same rule (BookingService::assignBookingToTrip).
 * @param items the bookings to filter
 * @param tripId the trip being linked to
 */
export const linkDialogBookings = (items: Booking[], tripId: number): Booking[] =>
	items.filter((item) => item.tripId === tripId
		|| (item.tripId === null && item.reviewState === 'confirmed'))

/**
 * The other bookings in this one's group of maybe-duplicates.
 *
 * Membership is symmetric by construction, so every card shows the whole group.
 * The predecessor stored a directed edge and unioned the two directions, which
 * worked for a pair and quietly failed for three: every booking pointed at the
 * oldest, so the hub saw both spokes and neither spoke saw the other.
 *
 * Discarded and archived bookings are left out rather than un-grouped: you have
 * already made a decision about them, so there is nothing left to compare, and a
 * flag on a row you have put away is noise. Restoring one brings the flag back,
 * which is the point of a soft state — `leaveDuplicateGroup` is the deliberate,
 * permanent answer.
 * @param items every booking
 * @param booking the booking whose card is being drawn
 */
export const possibleDuplicates = (items: Booking[], booking: Booking): Booking[] => {
	const group = booking.duplicateGroupId
	if (group === null || booking.reviewState === 'discarded' || booking.reviewState === 'archived') {
		return []
	}
	return items.filter((item) => item.id !== booking.id
		&& item.duplicateGroupId === group
		&& item.reviewState !== 'discarded'
		&& item.reviewState !== 'archived')
}

/**
 * Whether this booking has anyone left to be compared with.
 *
 * Delegates rather than testing `duplicateGroupId` directly: a group whose only
 * other member you discarded still has an id on every row, and a mark on a
 * booking with nothing to compare it against is a task the user cannot finish.
 * One rule, one place.
 * @param items every booking
 * @param booking the booking being marked
 */
export const hasPossibleDuplicate = (items: Booking[], booking: Booking): boolean =>
	possibleDuplicates(items, booking).length > 0
