import { describe, expect, it } from 'vitest'
import type { Booking, Trip } from '../../src/api'
import {
	filterTripsByPeriod,
	sortTrips,
	tripPeriod,
	tripRows,
	tripSpan,
} from '../../src/trips'

const now = new Date('2026-08-14T12:00:00Z')

const trip = (overrides: Partial<Trip> = {}): Trip => ({
	id: 1,
	name: 'Summer',
	startDate: null,
	endDate: null,
	notes: null,
	...overrides,
})

const booking = (overrides: Partial<Booking> = {}): Booking => ({
	id: 1,
	tripId: 1,
	type: 'flight',
	provider: 'KLM',
	bookingReference: 'YGUE6T',
	confirmationNumber: null,
	title: 'AMS → SOU',
	status: 'active',
	reviewState: 'draft',
	confidence: null,
	details: {},
	startDate: null,
	endDate: null,
	createdAt: null,
	updatedAt: null,
	confirmedAt: null,
	...overrides,
})

describe('tripSpan', () => {
	it('spans the earliest start to the latest end across bookings', () => {
		const span = tripSpan([
			booking({ startDate: '2026-09-05T10:00:00', endDate: '2026-09-05T14:00:00' }),
			booking({ startDate: '2026-09-01T08:00:00', endDate: '2026-09-03T11:00:00' }),
		])
		expect(span).toEqual({ start: '2026-09-01T08:00:00', end: '2026-09-05T14:00:00' })
	})

	it('lets a booking with no end date still extend the trip', () => {
		// A one-day car hire at the far end of a trip must not be invisible just
		// because it has no explicit end.
		const span = tripSpan([
			booking({ startDate: '2026-09-01T08:00:00', endDate: '2026-09-03T11:00:00' }),
			booking({ startDate: '2026-09-06T09:00:00', endDate: null }),
		])
		expect(span.end).toBe('2026-09-06T09:00:00')
	})

	it('is empty when nothing linked carries a date', () => {
		expect(tripSpan([booking({ startDate: null })])).toEqual({ start: null, end: null })
		expect(tripSpan([])).toEqual({ start: null, end: null })
	})
})

describe('tripPeriod', () => {
	it('places a trip either side of, or across, today', () => {
		expect(tripPeriod('2026-06-01T00:00:00', '2026-06-10T00:00:00', now)).toBe('past')
		expect(tripPeriod('2026-09-01T00:00:00', '2026-09-10T00:00:00', now)).toBe('future')
		// Started but not finished — the case the "Current" filter exists for.
		expect(tripPeriod('2026-08-01T00:00:00', '2026-08-20T00:00:00', now)).toBe('current')
	})

	it('treats an undated trip as its own case, not as future', () => {
		expect(tripPeriod(null, null, now)).toBe('undated')
	})

	it('uses the start when there is no end', () => {
		expect(tripPeriod('2026-06-01T00:00:00', null, now)).toBe('past')
	})
})

describe('tripRows', () => {
	const bookings = [
		booking({ id: 1, tripId: 1, type: 'flight', startDate: '2026-09-01T08:00:00', endDate: '2026-09-01T10:00:00' }),
		booking({ id: 2, tripId: 1, type: 'accommodation', startDate: '2026-09-01T15:00:00', endDate: '2026-09-05T11:00:00' }),
		booking({ id: 3, tripId: 1, type: 'flight', startDate: '2026-09-05T18:00:00', endDate: '2026-09-05T20:00:00' }),
		booking({ id: 4, tripId: 2, type: 'car_rental', startDate: '2026-06-01T09:00:00', endDate: null }),
	]

	it('derives span, distinct types and period from the linked bookings', () => {
		const [first] = tripRows([trip({ id: 1 })], bookings, now)
		expect(first.bookings.map((b) => b.id)).toEqual([1, 2, 3])
		expect(first.start).toBe('2026-09-01T08:00:00')
		expect(first.end).toBe('2026-09-05T20:00:00')
		// Two flights, one hotel — one lozenge per distinct type, not per booking.
		expect(first.types).toEqual(['accommodation', 'flight'])
		expect(first.period).toBe('future')
	})

	it('ignores the stored trip dates, which go stale as bookings move', () => {
		const [row] = tripRows([trip({ id: 2, startDate: '2030-01-01T00:00:00' })], bookings, now)
		expect(row.start).toBe('2026-06-01T09:00:00')
		expect(row.period).toBe('past')
	})

	it('gives an empty trip no dates and no types', () => {
		const [row] = tripRows([trip({ id: 99 })], bookings, now)
		expect(row.bookings).toEqual([])
		expect(row.types).toEqual([])
		expect(row.period).toBe('undated')
	})
})

describe('filterTripsByPeriod', () => {
	const rows = tripRows(
		[trip({ id: 1 }), trip({ id: 2 }), trip({ id: 3 })],
		[
			booking({ id: 1, tripId: 1, startDate: '2026-09-01T08:00:00' }),
			booking({ id: 2, tripId: 2, startDate: '2026-06-01T08:00:00' }),
		],
		now,
	)

	it('keeps one period at a time', () => {
		expect(filterTripsByPeriod(rows, 'future').map((r) => r.trip.id)).toEqual([1])
		expect(filterTripsByPeriod(rows, 'past').map((r) => r.trip.id)).toEqual([2])
	})

	it('shows undated trips under "all" only — otherwise they are unreachable', () => {
		expect(filterTripsByPeriod(rows, 'all')).toHaveLength(3)
		expect(filterTripsByPeriod(rows, 'current')).toEqual([])
		expect(filterTripsByPeriod(rows, 'future').map((r) => r.trip.id)).not.toContain(3)
	})
})

describe('sortTrips', () => {
	const rows = tripRows(
		[trip({ id: 1, name: 'zermatt' }), trip({ id: 2, name: 'Alps' }), trip({ id: 3, name: 'Undated' })],
		[
			booking({ id: 1, tripId: 1, startDate: '2026-09-01T08:00:00' }),
			booking({ id: 2, tripId: 2, startDate: '2026-06-01T08:00:00' }),
			booking({ id: 3, tripId: 2, startDate: '2026-06-02T08:00:00' }),
		],
		now,
	)

	it('sorts by name case-insensitively', () => {
		expect(sortTrips(rows, 'name', 'asc').map((r) => r.trip.id)).toEqual([2, 3, 1])
	})

	it('sorts chronologically, undated last in both directions', () => {
		// Plain chronology, deliberately unlike sortBookings: the period chips
		// already answer "what is coming up".
		expect(sortTrips(rows, 'travel', 'asc').map((r) => r.trip.id)).toEqual([2, 1, 3])
		expect(sortTrips(rows, 'travel', 'desc').map((r) => r.trip.id)).toEqual([1, 2, 3])
	})

	it('sorts by booking count numerically', () => {
		expect(sortTrips(rows, 'bookings', 'desc').map((r) => r.trip.id)).toEqual([2, 1, 3])
	})

	it('does not mutate the input', () => {
		const copy = [...rows]
		sortTrips(rows, 'name', 'asc')
		expect(rows).toEqual(copy)
	})
})
