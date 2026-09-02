import { describe, expect, it } from 'vitest'
import type { Booking } from '../../src/api'
import {
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
	hasPossibleDuplicate,
	hotelFields,
	linkDialogBookings,
	passengerLines,
	possibleDuplicates,
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
	sourceMessageId: null,
	duplicateGroupId: null,
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
		expect(reviewActions(booking({ reviewState: 'archived' }))).toEqual(['confirmed'])
	})

	it('restores a discard into the draft queue even when the booking had been confirmed', () => {
		// Undoing a discard says the booking is worth another look, not that it is
		// right, so it goes back to the state that means "needs review".
		const wasConfirmed = booking({ reviewState: 'discarded', confirmedAt: '2026-07-01T10:00:00+00:00' })
		expect(restoreTarget(wasConfirmed)).toBe('draft')
	})

	it('restores an archived booking straight back to confirmed', () => {
		// Archiving is completion, not rejection: un-archiving must not demand
		// that you vouch for the booking a second time. It also keeps its trip,
		// which is only legal for a confirmed booking.
		expect(restoreTarget(booking({ reviewState: 'archived' }))).toBe('confirmed')
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

	it('counts a booking travelling today as upcoming, however late it is read', () => {
		// Matches tripPeriod: day granularity, not instant. An 08:00 flight read
		// at 18:00 is still today's travel, not history.
		const evening = new Date('2026-08-28T18:00:00')
		const sameDay = [
			booking({ id: 1, startDate: '2026-08-28T08:00:00' }),
			booking({ id: 2, startDate: '2026-08-27T08:00:00' }),
		]
		expect(sortBookings(sameDay, 'travel', 'asc', evening).map((b) => b.id)).toEqual([1, 2])
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

	it('sorts by trip name, unlinked bookings last', () => {
		const names = { 1: 'zermatt', 2: 'Alps' }
		const linked = [
			booking({ id: 1, tripId: 1 }),
			booking({ id: 2, tripId: null }),
			booking({ id: 3, tripId: 2 }),
		]
		// On the name shown, not the id — and an unlinked booking has no value
		// at all, so it sinks rather than sorting as "trip zero".
		expect(sortBookings(linked, 'trip', 'asc', now, names).map((b) => b.id)).toEqual([3, 1, 2])
		expect(sortBookings(linked, 'trip', 'desc', now, names).at(-1)?.id).toBe(2)
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
		booking({ id: 2, tripId: null, reviewState: 'confirmed' }),
		booking({ id: 3, tripId: 7 }),
		booking({ id: 4, tripId: 9 }),
		booking({ id: 5, tripId: null, reviewState: 'confirmed' }),
	]

	it('collects the bookings linked to a given trip', () => {
		expect(bookingsForTrip(pool, 7).map((i) => i.id)).toEqual([1, 3])
		expect(bookingsForTrip(pool, 42)).toEqual([])
	})

	it('collects only the unassigned bookings, and only confirmed ones', () => {
		expect(unassignedBookings(pool).map((i) => i.id)).toEqual([2, 5])
	})

	it('the link dialog shows unassigned confirmed bookings plus the trip\'s own members', () => {
		expect(linkDialogBookings(pool, 7).map((i) => i.id)).toEqual([1, 2, 3, 5])
		expect(linkDialogBookings(pool, 9).map((i) => i.id)).toEqual([2, 4, 5])
	})

	it('the link dialog leaves out anything not confirmed', () => {
		// A trip groups travel you have decided is real; a draft is an extraction
		// nobody has vouched for yet. Drafts reach a trip through the picker
		// attached to confirming them.
		const notEligible = [
			...pool,
			booking({ id: 6, tripId: null, reviewState: 'draft' }),
			booking({ id: 7, tripId: null, reviewState: 'discarded' }),
			booking({ id: 8, tripId: null, reviewState: 'archived' }),
		]
		expect(linkDialogBookings(notEligible, 7).map((i) => i.id)).toEqual([1, 2, 3, 5])
	})

	it('keeps an already-linked booking listed whatever its state, so it can be unlinked', () => {
		// One linked while confirmed and later restored to draft would otherwise
		// be stranded on the trip with no way out of it.
		const restored = [booking({ id: 9, tripId: 7, reviewState: 'draft' })]
		expect(linkDialogBookings(restored, 7).map((i) => i.id)).toEqual([9])
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

describe('possibleDuplicates', () => {
	const a = booking({ id: 1, duplicateGroupId: 1 })
	const b = booking({ id: 2, duplicateGroupId: 1 })
	const c = booking({ id: 3, duplicateGroupId: 1 })

	it('shows the other member of a pair, from either side', () => {
		expect(possibleDuplicates([a, b], a).map((x) => x.id)).toEqual([2])
		expect(possibleDuplicates([a, b], b).map((x) => x.id)).toEqual([1])
	})

	it('shows every other member of a group of three, from every side', () => {
		// The point of the group: the directed-edge model this replaced pointed
		// every booking at the oldest, so only that one saw the whole cluster.
		expect(possibleDuplicates([a, b, c], a).map((x) => x.id)).toEqual([2, 3])
		expect(possibleDuplicates([a, b, c], b).map((x) => x.id)).toEqual([1, 3])
		expect(possibleDuplicates([a, b, c], c).map((x) => x.id)).toEqual([1, 2])
	})

	it('never pairs a booking with itself', () => {
		expect(possibleDuplicates([a], a)).toEqual([])
	})

	it('says nothing for a booking in no group', () => {
		const loner = booking({ id: 4, duplicateGroupId: null })
		expect(possibleDuplicates([a, b, loner], loner)).toEqual([])
	})

	it('keeps groups apart', () => {
		const other = booking({ id: 5, duplicateGroupId: 5 })
		expect(possibleDuplicates([a, b, other], a).map((x) => x.id)).toEqual([2])
	})

	it('hides a member once it is discarded — the question is answered', () => {
		const discarded = booking({ id: 2, duplicateGroupId: 1, reviewState: 'discarded' })
		expect(possibleDuplicates([a, discarded], a)).toEqual([])
		expect(possibleDuplicates([a, discarded, c], a).map((x) => x.id)).toEqual([3])
	})

	it('hides the section on a booking that is itself put away', () => {
		const archived = booking({ id: 1, duplicateGroupId: 1, reviewState: 'archived' })
		expect(possibleDuplicates([archived, b], archived)).toEqual([])
	})
})

describe('hasPossibleDuplicate', () => {
	it('marks both members of a live pair', () => {
		const pair = [booking({ id: 1, duplicateGroupId: 1 }), booking({ id: 2, duplicateGroupId: 1 })]
		expect(pair.map((item) => hasPossibleDuplicate(pair, item))).toEqual([true, true])
	})

	it('does not mark a booking whose only partner was discarded', () => {
		// A group id survives on the row, but a mark with nothing left to compare
		// against is a task the user cannot finish.
		const settled = [
			booking({ id: 1, duplicateGroupId: 1 }),
			booking({ id: 2, duplicateGroupId: 1, reviewState: 'discarded' }),
		]
		expect(hasPossibleDuplicate(settled, settled[0])).toBe(false)
	})

	it('does not mark a booking in no group', () => {
		const loner = booking({ id: 1 })
		expect(hasPossibleDuplicate([loner], loner)).toBe(false)
	})
})

describe('filterByReviewState', () => {
	const pool = [
		booking({ id: 1, duplicateGroupId: 5 }),
		booking({ id: 2, duplicateGroupId: 5 }),
		booking({ id: 3, reviewState: 'confirmed' }),
	]

	it('narrows to the bookings still waiting to be settled', () => {
		expect(filterByReviewState(pool, 'duplicates').map((i) => i.id)).toEqual([1, 2])
	})

	it('still filters by review state for every other key', () => {
		expect(filterByReviewState(pool, 'confirmed').map((i) => i.id)).toEqual([3])
		expect(filterByReviewState(pool, 'all')).toHaveLength(3)
	})
})
