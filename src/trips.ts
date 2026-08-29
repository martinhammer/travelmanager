import type { Booking, Trip, TripType } from './api'
import type { SortColumn, SortDirection } from './grid'
import { bookingTypes, bookingsForTrip } from './bookings'
import { localDate } from './grid'

/**
 * The trip types, in the order they are offered. Work first because it is the
 * one people file deliberately; leisure is what a trip is when nobody said.
 *
 * Kept here rather than in labels.ts because it is a decision (which types exist,
 * in what order) and this module is the tested one — labels.ts only words things.
 * It must stay in step with Trip::TYPES server-side, which validates.
 */
export const TRIP_TYPES: TripType[] = ['work', 'leisure']

/**
 * Whether a stored value is a type we know. Guards against a row written by a
 * newer version, or by hand: an unrecognised slug renders as no lozenge rather
 * than as itself, since a raw slug in the UI reads as a bug.
 * @param value the stored type, or null
 */
export const isTripType = (value: string | null): value is TripType =>
	value !== null && (TRIP_TYPES as string[]).includes(value)

/**
 * Where a trip sits relative to now. 'undated' is its own case rather than being
 * folded into 'future': a trip whose bookings carry no dates is not upcoming, it
 * is simply unplaceable, and quietly filing it under a period would make the
 * period filters lie.
 */
export type TripPeriod = 'current' | 'future' | 'past' | 'undated'

/** The Trips grid's sortable columns. */
export type TripSort = 'name' | 'type' | 'travel' | 'bookings'

/** See SortColumn in ./grid. Labels live in the component. */
export const TRIP_COLUMNS: SortColumn<TripSort>[] = [
	{ key: 'name', defaultDirection: 'asc' },
	{ key: 'type', defaultDirection: 'asc' },
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
 * Which period a span falls in, compared by **calendar date** against the
 * viewer's own today (see localDate). Comparing full timestamps instead put a
 * trip departing at 15:20 today under "Future" all morning.
 * @param start start of the span, or null
 * @param end end of the span, or null
 * @param now reference point; injectable so tests do not depend on today
 */
export const tripPeriod = (start: string | null, end: string | null, now: Date): TripPeriod => {
	if (!start) {
		return 'undated'
	}
	const today = localDate(now)
	if ((end ?? start).slice(0, 10) < today) {
		return 'past'
	}
	return start.slice(0, 10) > today ? 'future' : 'current'
}

/**
 * A trip's bookings in the order you will travel them: outbound flight, hotel,
 * car, return flight. The API returns bookings newest-ingested-first, which is
 * the order the emails happened to arrive and has nothing to do with the trip.
 * Undated bookings go last — there is nowhere else to put them.
 * @param items the bookings linked to a trip (not mutated)
 */
export const inTravelOrder = (items: Booking[]): Booking[] =>
	[...items].sort((a, b) => {
		if (a.startDate === b.startDate) {
			return 0
		}
		if (a.startDate === null) {
			return 1
		}
		if (b.startDate === null) {
			return -1
		}
		return a.startDate < b.startDate ? -1 : 1
	})

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
		const linked = inTravelOrder(bookingsForTrip(bookings, trip.id))
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

/**
 * Days either side of a trip that still count as part of it when suggesting one
 * for a booking. A return flight landing the morning after checkout belongs to
 * the trip; a strict overlap would miss it.
 */
export const SUGGESTION_TOLERANCE_DAYS = 2

/** How many suggestions to show; the rest stay in the full list below. */
const MAX_SUGGESTIONS = 3

// Shift a wall-clock date string by whole days, returning YYYY-MM-DD.
const shiftDays = (value: string, days: number): string => {
	const date = new Date(`${value.slice(0, 10)}T00:00:00Z`)
	date.setUTCDate(date.getUTCDate() + days)
	return date.toISOString().slice(0, 10)
}

/**
 * The trips a booking most likely belongs to: those whose derived span overlaps
 * the booking's own, within the tolerance. Nearest first.
 *
 * Returns nothing when the booking carries no dates, or when no trip has any —
 * which is what makes the dialog degrade to a plain searchable list rather than
 * showing an empty "Suggested" heading.
 * @param rows the trip rows to consider
 * @param booking the booking being filed
 * @param toleranceDays days of slack either side of the trip
 */
export const suggestedTrips = (
	rows: TripRow[],
	booking: Booking,
	toleranceDays: number = SUGGESTION_TOLERANCE_DAYS,
): TripRow[] => {
	const from = (booking.startDate ?? '').slice(0, 10)
	if (!from) {
		return []
	}
	const to = (booking.endDate ?? booking.startDate ?? '').slice(0, 10)

	return rows
		.filter((row) => {
			if (row.start === null) {
				return false
			}
			const tripFrom = shiftDays(row.start, -toleranceDays)
			const tripTo = shiftDays(row.end ?? row.start, toleranceDays)
			return from <= tripTo && to >= tripFrom
		})
		// Nearest start first, so the closest match is the one you read.
		.sort((a, b) => {
			const da = Math.abs(Date.parse(`${a.start!.slice(0, 10)}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`))
			const db = Math.abs(Date.parse(`${b.start!.slice(0, 10)}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`))
			return da - db
		})
		.slice(0, MAX_SUGGESTIONS)
}

/**
 * Trips matching a search box, newest travel first and undated last. Suggestions
 * already answer "which one is relevant", so this only has to be predictable.
 * @param rows the trip rows to search
 * @param query the search text; empty returns everything
 */
export const searchTrips = (rows: TripRow[], query: string): TripRow[] => {
	const needle = query.trim().toLowerCase()
	const matched = needle === ''
		? [...rows]
		: rows.filter((row) => row.trip.name.toLowerCase().includes(needle))
	return matched.sort((a, b) => {
		if (a.start === b.start) {
			return 0
		}
		if (a.start === null) {
			return 1
		}
		if (b.start === null) {
			return -1
		}
		return a.start < b.start ? 1 : -1
	})
}

/**
 * Whether the search text should offer to create a trip: it is non-empty and no
 * existing trip already carries that exact name.
 * @param rows every trip row (not just the matches)
 * @param query the search text
 */
export const canCreateTrip = (rows: TripRow[], query: string): boolean => {
	const name = query.trim().toLowerCase()
	return name !== '' && !rows.some((row) => row.trip.name.trim().toLowerCase() === name)
}

// The value a column sorts on; name lowercased so case never splits a group.
const sortValue = (row: TripRow, sort: TripSort): string | number | null => {
	switch (sort) {
	case 'name':
		return row.trip.name.toLowerCase() || null
	case 'type':
		// The slug, not the label: this module stays free of @nextcloud/l10n, and
		// the Bookings grid already sorts its own Type column the same way. An
		// unclassified trip has no value and sinks, rather than sorting as ''.
		return row.trip.type
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
