import { describe, expect, it } from 'vitest'
import type { Booking, Message } from '../../src/api'
import {
	DEFAULT_ROUTE,
	bookingsFromMessage,
	byId,
	detailLabel,
	detailRoute,
	formatRoute,
	matchRoute,
	messageForBooking,
	parseRoute,
	relatedBookings,
} from '../../src/detail'

const booking = (overrides: Partial<Booking> = {}): Booking => ({
	id: 1,
	tripId: null,
	type: 'flight',
	provider: 'KLM',
	bookingReference: 'YGUE6T',
	confirmationNumber: null,
	title: 'AMS → SOU',
	status: 'active',
	reviewState: 'draft',
	confidence: null,
	sourceMessageId: null,
	details: {},
	startDate: null,
	endDate: null,
	createdAt: null,
	updatedAt: null,
	confirmedAt: null,
	...overrides,
})

const message = (overrides: Partial<Message> = {}): Message => ({
	id: 1,
	mailbox: 'INBOX',
	messageId: '<abc@example.com>',
	subject: 'Your booking',
	sender: null,
	status: 'processed',
	failureKind: null,
	issueReasons: [],
	relatedBookingIds: [],
	error: null,
	lastResponse: null,
	attempts: 1,
	canRetry: true,
	sentAt: null,
	processedAt: null,
	...overrides,
})

describe('parseRoute', () => {
	it('reads a view and an optional entity', () => {
		expect(parseRoute('#/bookings')).toEqual({ view: 'bookings', detail: null })
		expect(parseRoute('#/bookings/42')).toEqual({ view: 'bookings', detail: { type: 'booking', id: 42 } })
		expect(parseRoute('#/trips/7')).toEqual({ view: 'trips', detail: { type: 'trip', id: 7 } })
		expect(parseRoute('#/messages/19')).toEqual({ view: 'messages', detail: { type: 'message', id: 19 } })
	})

	it('tolerates the hash being written with or without slashes', () => {
		expect(parseRoute('bookings/42')).toEqual(parseRoute('#/bookings/42'))
		expect(parseRoute('#bookings/42')).toEqual(parseRoute('#/bookings/42'))
	})

	it('falls back to the default rather than a blank screen', () => {
		// A stale or hand-edited URL should still land somewhere usable.
		expect(parseRoute('')).toEqual(DEFAULT_ROUTE)
		expect(parseRoute('#/nonsense/1')).toEqual(DEFAULT_ROUTE)
	})
})

describe('matchRoute', () => {
	it('reports a hash that is none of ours as null, not as the default', () => {
		// Nextcloud's own nav items are <a href="#">, so a bare '#' lands in the
		// history on every navigation click. Treating it as "go to Bookings" is
		// what broke moving between views: the click switched, the stray hash
		// switched straight back.
		expect(matchRoute('#')).toBeNull()
		expect(matchRoute('')).toBeNull()
		expect(matchRoute('#/nonsense')).toBeNull()
	})

	it('still resolves real routes', () => {
		expect(matchRoute('#/trips/7')).toEqual({ view: 'trips', detail: { type: 'trip', id: 7 } })
	})

	it('ignores an id that is not a positive integer', () => {
		// Number('') is 0 and Number('abc') is NaN — neither is a real id.
		expect(parseRoute('#/bookings/abc').detail).toBeNull()
		expect(parseRoute('#/bookings/0').detail).toBeNull()
		expect(parseRoute('#/bookings/-3').detail).toBeNull()
	})
})

describe('formatRoute', () => {
	it('round-trips through parseRoute', () => {
		for (const hash of ['#/bookings', '#/bookings/42', '#/trips/7', '#/messages/19']) {
			expect(formatRoute(parseRoute(hash))).toBe(hash)
		}
	})
})

describe('detailRoute', () => {
	it('switches the list underneath to the entity it belongs to', () => {
		// Opening a booking from a message also moves the view, so closing the
		// panel leaves you somewhere coherent rather than on an unrelated list.
		expect(detailRoute('booking', 42)).toEqual({ view: 'bookings', detail: { type: 'booking', id: 42 } })
		expect(detailRoute('trip', 7).view).toBe('trips')
	})
})

describe('bookingsFromMessage', () => {
	it('finds every booking an email produced, matched on Message-ID', () => {
		const pool = [
			booking({ id: 1, sourceMessageId: '<abc@example.com>' }),
			booking({ id: 2, sourceMessageId: '<other@example.com>' }),
			// One email can yield a flight *and* a hotel.
			booking({ id: 3, sourceMessageId: '<abc@example.com>', type: 'accommodation' }),
		]
		expect(bookingsFromMessage(pool, message()).map((b) => b.id)).toEqual([1, 3])
	})

	it('returns nothing for an email that created no booking', () => {
		expect(bookingsFromMessage([booking({ sourceMessageId: null })], message())).toEqual([])
	})
})

describe('messageForBooking', () => {
	it('finds the loaded source message', () => {
		const found = messageForBooking([message({ id: 5 })], booking({ sourceMessageId: '<abc@example.com>' }))
		expect(found?.id).toBe(5)
	})

	it('is null when the booking has no source, or the message is not loaded', () => {
		// Not loaded is not the same as does not exist — the list caps at 200 rows,
		// so the caller falls back to fetching it by Message-ID.
		expect(messageForBooking([message()], booking({ sourceMessageId: null }))).toBeNull()
		expect(messageForBooking([], booking({ sourceMessageId: '<abc@example.com>' }))).toBeNull()
	})
})

describe('relatedBookings', () => {
	const pool = [booking({ id: 1 }), booking({ id: 2 }), booking({ id: 3 })]

	it('resolves the ids a related email carries', () => {
		// The email did not create these — an earlier one did — so the link is
		// stored rather than derived from sourceMessageId.
		const related = message({ status: 'related', relatedBookingIds: [3, 1] })
		expect(relatedBookings(pool, related).map((b) => b.id)).toEqual([3, 1])
	})

	it('skips a booking that has since been permanently deleted', () => {
		const related = message({ relatedBookingIds: [2, 99] })
		expect(relatedBookings(pool, related).map((b) => b.id)).toEqual([2])
	})

	it('is empty for an ordinary message', () => {
		expect(relatedBookings(pool, message())).toEqual([])
	})
})

describe('byId', () => {
	it('finds an entity or returns null', () => {
		expect(byId([booking({ id: 3 })], 3)?.id).toBe(3)
		expect(byId([booking({ id: 3 })], 4)).toBeNull()
	})
})

describe('detailLabel', () => {
	it('names each kind of thing, falling back when the title is empty', () => {
		expect(detailLabel('booking', booking({ title: 'AMS → SOU' }))).toBe('AMS → SOU')
		expect(detailLabel('booking', booking({ title: null }))).toBe('flight')
		expect(detailLabel('message', message({ subject: null }))).toBe('<abc@example.com>')
	})
})
