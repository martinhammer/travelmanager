import { describe, expect, it } from 'vitest'
import type { Booking } from '../../src/api'
import {
	bookingHeaderFields,
	bookingsForTrip,
	carFields,
	decodeHtmlEntities,
	draftCount,
	filterByStatus,
	flightSegmentFields,
	formatDateTime,
	hotelFields,
	linkDialogBookings,
	passengerLines,
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
	status: 'draft',
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
	booking({ id: 1, status: 'draft' }),
	booking({ id: 2, status: 'draft' }),
	booking({ id: 3, status: 'confirmed' }),
	booking({ id: 4, status: 'cancelled' }),
]

describe('filterByStatus', () => {
	it('filters to a single status', () => {
		expect(filterByStatus(items, 'draft').map((i) => i.id)).toEqual([1, 2])
		expect(filterByStatus(items, 'confirmed').map((i) => i.id)).toEqual([3])
	})

	it('returns everything for the "all" sentinel', () => {
		expect(filterByStatus(items, 'all')).toHaveLength(4)
	})

	it('does not mutate the input', () => {
		const copy = [...items]
		filterByStatus(items, 'draft')
		expect(items).toEqual(copy)
	})
})

describe('draftCount', () => {
	it('counts only drafts', () => {
		expect(draftCount(items)).toBe(2)
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
	it('labels the flight header with provider and reference', () => {
		expect(bookingHeaderFields(booking({ type: 'flight', provider: 'KLM', bookingReference: 'YGUE6T', confirmationNumber: '29276863' }))).toEqual([
			{ label: 'Booking type', value: 'flight' },
			{ label: 'Provider', value: 'KLM' },
			{ label: 'Booking reference', value: 'YGUE6T' },
			{ label: 'Confirmation number', value: '29276863' },
		])
	})

	it('labels the car provider as "Supplier" and omits empty fields', () => {
		expect(bookingHeaderFields(booking({ type: 'car_rental', provider: 'Holiday Autos', bookingReference: null, confirmationNumber: null }))).toEqual([
			{ label: 'Booking type', value: 'car_rental' },
			{ label: 'Supplier', value: 'Holiday Autos' },
		])
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
