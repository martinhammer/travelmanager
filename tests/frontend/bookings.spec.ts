import { describe, expect, it } from 'vitest'
import type { BookingWithSegments } from '../../src/api'
import { draftCount, filterByStatus } from '../../src/bookings'

const booking = (id: number, status: string): BookingWithSegments => ({
	booking: {
		id,
		tripId: null,
		type: 'flight',
		provider: 'SAS',
		bookingReference: 'ABC' + id,
		title: 'Trip ' + id,
		status,
		confidence: null,
		createdAt: null,
		updatedAt: null,
		confirmedAt: null,
	},
	segments: [],
})

const items: BookingWithSegments[] = [
	booking(1, 'draft'),
	booking(2, 'draft'),
	booking(3, 'confirmed'),
	booking(4, 'cancelled'),
]

describe('filterByStatus', () => {
	it('filters to a single status', () => {
		expect(filterByStatus(items, 'draft').map((i) => i.booking.id)).toEqual([1, 2])
		expect(filterByStatus(items, 'confirmed').map((i) => i.booking.id)).toEqual([3])
	})

	it('returns everything for the "all" sentinel', () => {
		expect(filterByStatus(items, 'all')).toHaveLength(4)
	})

	it('returns an empty array for an unknown status', () => {
		expect(filterByStatus(items, 'nope')).toEqual([])
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

	it('is zero when there are no drafts', () => {
		expect(draftCount([booking(5, 'confirmed')])).toBe(0)
	})
})
