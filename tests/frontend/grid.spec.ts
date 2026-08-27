import { describe, expect, it } from 'vitest'
import { formatTimestamp, nextSortDirection } from '../../src/grid'
import { MESSAGE_COLUMNS } from '../../src/messages'
import { BOOKING_COLUMNS } from '../../src/bookings'

describe('nextSortDirection', () => {
	it('starts a new column at its own default', () => {
		// Dates open newest-first; names open A→Z.
		expect(nextSortDirection(MESSAGE_COLUMNS, 'received', 'sender', 'asc')).toBe('desc')
		expect(nextSortDirection(MESSAGE_COLUMNS, 'sender', 'received', 'desc')).toBe('asc')
	})

	it('flips the column already sorted on', () => {
		expect(nextSortDirection(MESSAGE_COLUMNS, 'received', 'received', 'desc')).toBe('asc')
		expect(nextSortDirection(MESSAGE_COLUMNS, 'received', 'received', 'asc')).toBe('desc')
	})

	it('works the same for the Bookings grid — the behaviour is the columns, not the view', () => {
		expect(nextSortDirection(BOOKING_COLUMNS, 'added', 'title', 'asc')).toBe('desc')
		expect(nextSortDirection(BOOKING_COLUMNS, 'travel', 'title', 'desc')).toBe('asc')
		expect(nextSortDirection(BOOKING_COLUMNS, 'title', 'title', 'asc')).toBe('desc')
	})
})

describe('formatTimestamp', () => {
	it('renders an ATOM timestamp and passes through what it cannot parse', () => {
		expect(formatTimestamp('2026-08-14T10:00:00+00:00')).not.toBe('')
		expect(formatTimestamp(null)).toBe('')
		expect(formatTimestamp('not a date')).toBe('not a date')
	})
})
