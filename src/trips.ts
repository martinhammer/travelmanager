import type { Booking, Trip } from './api'
import type { SortColumn, SortDirection } from './grid'
import { bookingTypes, bookingsForTrip } from './bookings'

/**
 * Where a trip sits relative to now. 'undated' is its own case rather than being
 * folded into 'future': a trip whose bookings carry no dates is not upcoming, it
 * is simply unplaceable, and quietly filing it under a period would make the
 * period filters lie.
 */
export type TripPeriod = 'current' | 'future' | 'past' | 'undated'

/** The Trips grid's sortable columns. */
export type TripSort = 'name' | 'travel' | 'bookings'

/** See SortColumn in ./grid. Labels live in the component. */
export const TRIP_COLUMNS: SortColumn<TripSort>[] = [
	{ key: 'name', defaultDirection: 'asc' },
	{ key: 'travel', defaultDirection: 'asc' },
	{ key: 'bookings', defaultDirection: 'desc' },
]

/**
 * A trip plus everything derived from the bookings linked to it.
 *
 * Trips carry their own `startDate`/`endDate` columns, but those are user-entered
 * and go stale the moment a booking is linked or unlinked. The grid shows the
 * span the bookings actually describe, so it cannot disagree with the rows
 * underneath it.
 */
export interface TripRow {
	trip: Trip
	bookings: Booking[]
	/** Earliest start among the linked bookings, or null when none is dated. */
	start: string | null
	/** Latest end (falling back to start) among the linked bookings. */
	end: string | null
	/** Distinct booking types present, sorted — one lozenge each. */
	types: string[]
	period: TripPeriod
}

/**
 * The span the linked bookings describe: earliest start to latest end. A booking
 * with no end date contributes its start, so a one-day booking still extends the
 * trip's end.
 * @param items the bookings linked to the trip
 */
export const tripSpan = (items: Booking[]): { start: string | null, end: string | null } => {
	let start: string | null = null
	let end: string | null = null
	for (const item of items) {
		if (!item.startDate) {
			continue
		}
		if (start === null || item.startDate < start) {
			start = item.startDate
		}
		const itemEnd = item.endDate ?? item.startDate
		if (end === null || itemEnd > end) {
			end = itemEnd
		}
	}
	return { start, end }
}

/**
 * Which period a span falls in, compared against local wall-clock (V8: booking
 * times carry no offset, so this is a string comparison, not a date one).
 * @param start start of the span, or null
 * @param end end of the span, or null
 * @param now reference point; injectable so tests do not depend on today
 */
export const tripPeriod = (start: string | null, end: string | null, now: Date): TripPeriod => {
	if (!start) {
		return 'undated'
	}
	const today = now.toISOString().slice(0, 19)
	if ((end ?? start) < today) {
		return 'past'
	}
	return start > today ? 'future' : 'current'
}

/**
 * Build the grid's rows: each trip with its bookings, derived span, types and
 * period. Computed once per render rather than per cell, so the derivation runs
 * once instead of once for every column that needs it.
 * @param trips the trips to describe
 * @param bookings every booking (filtered per trip inside)
 * @param now reference point for the period; injectable for tests
 */
export const tripRows = (trips: Trip[], bookings: Booking[], now: Date = new Date()): TripRow[] =>
	trips.map((trip) => {
		const linked = bookingsForTrip(bookings, trip.id)
		const { start, end } = tripSpan(linked)
		return {
			trip,
			bookings: linked,
			start,
			end,
			types: bookingTypes(linked),
			period: tripPeriod(start, end, now),
		}
	})

/**
 * Filter rows by period. The sentinel 'all' returns everything — and is the only
 * filter that shows undated trips, which would otherwise be unreachable.
 * @param rows the rows to filter
 * @param period the period to keep, or 'all'
 */
export const filterTripsByPeriod = (rows: TripRow[], period: string): TripRow[] =>
	period === 'all' ? rows : rows.filter((row) => row.period === period)

// The value a column sorts on; name lowercased so case never splits a group.
const sortValue = (row: TripRow, sort: TripSort): string | number | null => {
	switch (sort) {
	case 'name':
		return row.trip.name.toLowerCase() || null
	case 'travel':
		return row.start
	case 'bookings':
		return row.bookings.length
	}
}

/**
 * Order rows by one column, valueless rows last in both directions.
 *
 * Unlike sortBookings, 'travel' here is a plain chronological sort: the
 * Current/Future/Past filters already answer "what is coming up", so re-ranking
 * the column around today as well would fight them.
 * @param rows the rows to order (not mutated)
 * @param sort the column to order by
 * @param direction 'asc' or 'desc'
 */
export const sortTrips = (rows: TripRow[], sort: TripSort, direction: SortDirection = 'asc'): TripRow[] => {
	const sign = direction === 'asc' ? 1 : -1
	return [...rows].sort((a, b) => {
		const av = sortValue(a, sort)
		const bv = sortValue(b, sort)
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
