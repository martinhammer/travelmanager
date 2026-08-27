import { describe, expect, it } from 'vitest'
import type { Booking } from '../../src/api'
import {
	bookingHeaderFields,
	bookingSpan,
	bookingTypes,
	bookingsForTrip,
	carFields,
	filterBookings,
	sortBookings,
	decodeHtmlEntities,
	draftCount,
	filterByReviewState,
	flightSegmentFields,
	formatDateTime,
	hotelFields,
	linkDialogBookings,
	passengerLines,
	restoreTarget,
	reviewActions,
	unassignedBookings,
} from '../../src/bookings'

const booking = (overrides: Partial<Booking> = {}): Booking => ({
	id: 1,
	tripId: null,
	type: 'flight',
	provider: 'SAS',
	bookingReference: 'ABC123',
	confirmationNumber: null,
	title: 'Trip',
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

const items: Booking[] = [
	booking({ id: 1, reviewState: 'draft' }),
	booking({ id: 2, reviewState: 'draft' }),
	booking({ id: 3, reviewState: 'confirmed' }),
	booking({ id: 4, reviewState: 'discarded', status: 'cancelled' }),
]

describe('filterByReviewState', () => {
	it('filters to a single review state', () => {
		expect(filterByReviewState(items, 'draft').map((i) => i.id)).toEqual([1, 2])
		expect(filterByReviewState(items, 'confirmed').map((i) => i.id)).toEqual([3])
	})

	it('returns everything for the "all" sentinel, discarded included', () => {
		expect(filterByReviewState(items, 'all')).toHaveLength(4)
		expect(filterByReviewState(items, 'all').map((i) => i.id)).toContain(4)
	})

	it('is independent of the provider-side status', () => {
		// #4 is cancelled by the airline but that is not a review state.
		expect(filterByReviewState(items, 'cancelled')).toEqual([])
		expect(filterByReviewState(items, 'discarded').map((i) => i.id)).toEqual([4])
	})

	it('does not mutate the input', () => {
		const copy = [...items]
		filterByReviewState(items, 'draft')
		expect(items).toEqual(copy)
	})
})

describe('draftCount', () => {
	it('counts only drafts', () => {
		expect(draftCount(items)).toBe(2)
	})
})

describe('review transitions', () => {
	it('offers confirm and discard on a draft', () => {
		expect(reviewActions(booking({ reviewState: 'draft' }))).toEqual(['confirmed', 'discarded'])
	})

	it('offers archive and discard on a confirmed booking', () => {
		expect(reviewActions(booking({ reviewState: 'confirmed' }))).toEqual(['archived', 'discarded'])
	})

	it('offers only a way back from discarded and archived', () => {
		expect(reviewActions(booking({ reviewState: 'discarded' }))).toEqual(['draft'])
		expect(reviewActions(booking({ reviewState: 'archived' }))).toEqual(['draft'])
	})

	it('restores a previously confirmed booking to confirmed, not back into the draft queue', () => {
		const wasConfirmed = booking({ reviewState: 'archived', confirmedAt: '2026-07-01T10:00:00+00:00' })
		expect(restoreTarget(wasConfirmed)).toBe('confirmed')
		expect(reviewActions(wasConfirmed)).toEqual(['confirmed'])
		expect(restoreTarget(booking({ reviewState: 'discarded' }))).toBe('draft')
	})
})

describe('sortBookings', () => {
	const now = new Date('2026-08-14T12:00:00Z')
	const pool = [
		booking({ id: 1, startDate: '2026-06-24T18:00:00', createdAt: '2026-01-01T00:00:00+00:00' }),
		booking({ id: 2, startDate: '2026-09-01T08:00:00', createdAt: '2026-02-01T00:00:00+00:00' }),
		booking({ id: 3, startDate: null, createdAt: '2026-03-01T00:00:00+00:00' }),
		booking({ id: 4, startDate: '2026-12-24T10:00:00', createdAt: '2026-04-01T00:00:00+00:00' }),
		booking({ id: 5, startDate: '2026-07-30T09:00:00', createdAt: '2026-05-01T00:00:00+00:00' }),
	]

	it('puts the next trip first, past travel below, undated last', () => {
		// 2 and 4 are ahead (soonest first); 5 and 1 are past (most recent
		// first); 3 has no date at all.
		expect(sortBookings(pool, 'travel', 'asc', now).map((b) => b.id)).toEqual([2, 4, 5, 1, 3])
	})

	it('falls back to plain reverse chronology descending — looking back, not ahead', () => {
		// Deliberately NOT the reverse of the ascending order: that one groups
		// future before past, which only makes sense pointing forwards.
		expect(sortBookings(pool, 'travel', 'desc', now).map((b) => b.id)).toEqual([4, 2, 5, 1, 3])
	})

	it('orders by creation when asked', () => {
		expect(sortBookings(pool, 'added', 'desc', now).map((b) => b.id)).toEqual([5, 4, 3, 2, 1])
	})

	it('sorts text columns case-insensitively, blanks last', () => {
		const pairs = [
			booking({ id: 1, provider: 'united' }),
			booking({ id: 2, provider: null }),
			booking({ id: 3, provider: 'KLM' }),
		]
		expect(sortBookings(pairs, 'provider', 'asc', now).map((b) => b.id)).toEqual([3, 1, 2])
		// Unsortable, not smallest: the blank stays last either way.
		expect(sortBookings(pairs, 'provider', 'desc', now).at(-1)?.id).toBe(2)
	})

	it('does not mutate the input', () => {
		const copy = [...pool]
		sortBookings(pool, 'travel', 'asc', now)
		expect(pool).toEqual(copy)
	})
})

describe('booking filters', () => {
	const pool = [
		booking({ id: 1, type: 'flight', reviewState: 'draft' }),
		booking({ id: 2, type: 'car_rental', reviewState: 'draft' }),
		booking({ id: 3, type: 'flight', reviewState: 'confirmed' }),
	]

	it('combines the review-state and type filters', () => {
		expect(filterBookings(pool, 'draft', 'all').map((b) => b.id)).toEqual([1, 2])
		expect(filterBookings(pool, 'all', 'flight').map((b) => b.id)).toEqual([1, 3])
		expect(filterBookings(pool, 'draft', 'flight').map((b) => b.id)).toEqual([1])
		expect(filterBookings(pool, 'all', 'all')).toHaveLength(3)
	})

	it('offers only the types actually present', () => {
		expect(bookingTypes(pool)).toEqual(['car_rental', 'flight'])
	})
})

describe('trip grouping', () => {
	const pool = [
		booking({ id: 1, tripId: 7 }),
		booking({ id: 2, tripId: null }),
		booking({ id: 3, tripId: 7 }),
		booking({ id: 4, tripId: 9 }),
		booking({ id: 5, tripId: null }),
	]

	it('collects the bookings linked to a given trip', () => {
		expect(bookingsForTrip(pool, 7).map((i) => i.id)).toEqual([1, 3])
		expect(bookingsForTrip(pool, 42)).toEqual([])
	})

	it('collects only the unassigned bookings', () => {
		expect(unassignedBookings(pool).map((i) => i.id)).toEqual([2, 5])
	})

	it('the link dialog shows unassigned bookings plus the trip\'s own members', () => {
		expect(linkDialogBookings(pool, 7).map((i) => i.id)).toEqual([1, 2, 3, 5])
		expect(linkDialogBookings(pool, 9).map((i) => i.id)).toEqual([2, 4, 5])
	})

	it('the link dialog leaves out discarded and archived bookings', () => {
		const withTombstones = [
			...pool,
			booking({ id: 6, tripId: null, reviewState: 'discarded' }),
			booking({ id: 7, tripId: null, reviewState: 'archived' }),
		]
		expect(linkDialogBookings(withTombstones, 7).map((i) => i.id)).toEqual([1, 2, 3, 5])
	})
})

describe('formatDateTime', () => {
	it('trims seconds and the T separator', () => {
		expect(formatDateTime('2026-08-28T17:30:00')).toBe('2026-08-28 17:30')
	})

	it('drops a midnight time (date-only value)', () => {
		expect(formatDateTime('2026-07-25T00:00:00')).toBe('2026-07-25')
	})

	it('returns an empty string for null/undefined', () => {
		expect(formatDateTime(null)).toBe('')
		expect(formatDateTime(undefined)).toBe('')
	})

	it('leaves unrecognised values untouched', () => {
		expect(formatDateTime('sometime next week')).toBe('sometime next week')
	})
})

describe('bookingHeaderFields', () => {
	it('carries only what the grid does not already show as a column', () => {
		// Type, provider and reference are columns now; repeating them in the
		// expanded row would be the same value twice.
		expect(bookingHeaderFields(booking({ type: 'flight', provider: 'KLM', bookingReference: 'YGUE6T', confirmationNumber: '29276863' }))).toEqual([
			{ label: 'Confirmation number', value: '29276863' },
		])
	})

	it('omits empty fields, leaving nothing to render', () => {
		expect(bookingHeaderFields(booking({ type: 'car_rental', provider: 'Holiday Autos', confirmationNumber: null }))).toEqual([])
	})
})

describe('bookingSpan', () => {
	it('shows a range when the end date differs, one date when it does not', () => {
		expect(bookingSpan(booking({ startDate: '2026-07-25T08:35:00', endDate: '2026-07-28T11:00:00' })))
			.toBe('2026-07-25 → 2026-07-28')
		expect(bookingSpan(booking({ startDate: '2026-07-25T08:35:00', endDate: '2026-07-25T22:00:00' })))
			.toBe('2026-07-25')
		expect(bookingSpan(booking({ startDate: '2026-07-25T08:35:00', endDate: null })))
			.toBe('2026-07-25')
	})

	it('is empty without a start date, so the column renders a dash', () => {
		expect(bookingSpan(booking({ startDate: null }))).toBe('')
	})
})

describe('flightSegmentFields', () => {
	it('describes a leg with carrier, route and times', () => {
		expect(flightSegmentFields({
			carrier: 'DY',
			flightNumber: '1505',
			origin: 'Prague',
			destination: 'Oslo',
			departureLocal: '2026-08-09T21:55:00',
			departureTimezone: 'Europe/Prague',
			arrivalLocal: '2026-08-09T23:50:00',
		})).toEqual([
			{ label: 'Flight', value: 'DY 1505' },
			{ label: 'Origin', value: 'Prague' },
			{ label: 'Destination', value: 'Oslo' },
			{ label: 'Departure', value: '2026-08-09 21:55 (Europe/Prague)' },
			{ label: 'Arrival', value: '2026-08-09 23:50' },
		])
	})
})

describe('carFields', () => {
	it('describes supplier, car and pickup/dropoff', () => {
		expect(carFields({
			supplier: 'Holiday Autos',
			rentalCompany: 'Europcar',
			carType: 'Compact - VW Golf or similar',
			carFeatures: ['automatic', 'air conditioning'],
			driver: { name: 'Jane Doe' },
			pickup: { location: 'Gran Canaria - Airport', local: '2026-06-24T18:00:00' },
			dropoff: { location: 'Gran Canaria - Airport', local: '2026-06-28T12:30:00' },
		})).toEqual([
			{ label: 'Supplier', value: 'Holiday Autos' },
			{ label: 'Rental company', value: 'Europcar' },
			{ label: 'Car type', value: 'Compact - VW Golf or similar' },
			{ label: 'Features', value: 'automatic, air conditioning' },
			{ label: 'Driver', value: 'Jane Doe' },
			{ label: 'Pick-up', value: 'Gran Canaria - Airport' },
			{ label: 'Pick-up time', value: '2026-06-24 18:00' },
			{ label: 'Drop-off', value: 'Gran Canaria - Airport' },
			{ label: 'Drop-off time', value: '2026-06-28 12:30' },
		])
	})
})

describe('hotelFields', () => {
	it('describes property, dates, room and guests', () => {
		expect(hotelFields({
			propertyName: 'Hotel Sol',
			checkIn: { local: '2026-07-25T15:00:00' },
			checkOut: { local: '2026-07-28T11:00:00' },
			roomType: 'Double',
			numberOfRooms: 1,
			guests: [{ name: 'Jane Doe' }, { name: 'John Doe' }],
		})).toEqual([
			{ label: 'Property', value: 'Hotel Sol' },
			{ label: 'Check-in', value: '2026-07-25 15:00' },
			{ label: 'Check-out', value: '2026-07-28 11:00' },
			{ label: 'Room', value: 'Double' },
			{ label: 'Rooms', value: '1' },
			{ label: 'Guests', value: 'Jane Doe, John Doe' },
		])
	})
})

describe('passengerLines', () => {
	it('appends frequent flyer and baggage in parentheses', () => {
		expect(passengerLines({
			passengers: [
				{ name: 'Jane Doe', frequentFlyer: 'SK123', baggage: '1x23kg' },
				{ name: 'John Doe' },
			],
		})).toEqual(['Jane Doe (FF SK123, bag 1x23kg)', 'John Doe'])
	})

	it('is empty when there are no passengers', () => {
		expect(passengerLines({})).toEqual([])
	})
})

describe('decodeHtmlEntities', () => {
	it('decodes named entities', () => {
		expect(decodeHtmlEntities('Smith &amp; Co &mdash; Sebie&apos;s birthday')).toBe('Smith & Co — Sebie\'s birthday')
	})

	it('decodes decimal and hex numeric refs', () => {
		expect(decodeHtmlEntities('Sebie&#39;s birthday')).toBe('Sebie\'s birthday')
		expect(decodeHtmlEntities('Sebie&#x27;s birthday')).toBe('Sebie\'s birthday')
	})

	it('leaves plain text and unknown refs untouched', () => {
		expect(decodeHtmlEntities('August Norway trip')).toBe('August Norway trip')
		expect(decodeHtmlEntities('Rock &amp; Roll &unknown; test')).toBe('Rock & Roll &unknown; test')
	})

	it('resolves double-encoded entities (e.g. "&amp;#39;")', () => {
		expect(decodeHtmlEntities('August Norway Sebie&amp;#39;s birthday')).toBe('August Norway Sebie\'s birthday')
	})
})
