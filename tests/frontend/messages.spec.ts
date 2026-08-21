import { describe, expect, it } from 'vitest'
import type { Message } from '../../src/api'
import {
	failureKindLabel,
	filterMessagesByStatus,
	formatTimestamp,
	hasDetails,
	messageDetails,
	messageStatusLabel,
	needsAttention,
	retryable,
	sortMessages,
	statusNotice,
} from '../../src/messages'

const message = (overrides: Partial<Message> = {}): Message => ({
	id: 1,
	mailbox: 'INBOX',
	messageId: '<abc@example.com>',
	subject: 'Your booking',
	status: 'processed',
	failureKind: null,
	error: null,
	lastResponse: null,
	attempts: 1,
	canRetry: true,
	sentAt: null,
	processedAt: null,
	...overrides,
})

describe('needsAttention', () => {
	it('flags failures and rejected extractions, not empty emails', () => {
		const items = [
			message({ id: 1, status: 'processed' }),
			message({ id: 2, status: 'failed' }),
			message({ id: 3, status: 'dropped' }),
			// A newsletter really had no booking in it — nothing for a human to do.
			message({ id: 4, status: 'no_booking' }),
			message({ id: 5, status: 'processing' }),
		]
		expect(needsAttention(items).map((m) => m.id)).toEqual([2, 3])
	})
})

describe('retryable', () => {
	it('needs a retained body', () => {
		expect(retryable(message({ status: 'failed', canRetry: true }))).toBe(true)
		expect(retryable(message({ status: 'failed', canRetry: false }))).toBe(false)
	})

	it('does not offer a retry while a task is still in flight', () => {
		const inFlight = message({ status: 'processing', canRetry: true })
		expect(retryable(inFlight)).toBe(false)
		// ...but the button is still rendered (disabled), so the control does not
		// vanish mid-run — canRetry drives visibility, retryable drives enablement.
		expect(inFlight.canRetry).toBe(true)
	})
})

describe('messageStatusLabel', () => {
	it('words the two zero-booking outcomes differently', () => {
		expect(messageStatusLabel('no_booking')).not.toBe(messageStatusLabel('dropped'))
	})

	it('falls back to the raw status it does not know', () => {
		expect(messageStatusLabel('something_new')).toBe('something_new')
	})
})

describe('statusNotice', () => {
	it('explains a related row without calling it a failure', () => {
		const notice = statusNotice(message({ status: 'related' }))
		expect(notice).toContain('does not apply updates yet')
		// Not a fault: the failure channel stays silent for it.
		expect(failureKindLabel(message({ status: 'related' }))).toBe('')
	})

	it('says nothing for ordinary rows', () => {
		expect(statusNotice(message({ status: 'processed' }))).toBe('')
	})

	it('is not counted as needing attention — a notice you cannot clear', () => {
		expect(needsAttention([message({ status: 'related' })])).toEqual([])
	})
})

describe('failureKindLabel', () => {
	it('describes a classified failure and stays silent otherwise', () => {
		expect(failureKindLabel(message({ failureKind: 'provider' }))).toContain('provider')
		expect(failureKindLabel(message({ failureKind: null }))).toBe('')
	})
})

describe('filterMessagesByStatus', () => {
	const items = [message({ id: 1, status: 'processed' }), message({ id: 2, status: 'dropped' })]

	it('filters, with an "all" sentinel', () => {
		expect(filterMessagesByStatus(items, 'dropped').map((m) => m.id)).toEqual([2])
		expect(filterMessagesByStatus(items, 'all')).toHaveLength(2)
	})

	it('treats "attention" as the failed/dropped rows', () => {
		const pool = [...items, message({ id: 3, status: 'failed' }), message({ id: 4, status: 'no_booking' })]
		expect(filterMessagesByStatus(pool, 'attention').map((m) => m.id)).toEqual([2, 3])
	})
})

describe('sortMessages', () => {
	const pool = [
		message({ id: 1, sentAt: '2026-08-01T10:00:00+00:00', processedAt: '2026-08-14T10:00:00+00:00' }),
		message({ id: 2, sentAt: '2026-08-10T10:00:00+00:00', processedAt: '2026-08-10T10:00:00+00:00' }),
		message({ id: 3, sentAt: null, processedAt: '2026-08-12T10:00:00+00:00' }),
	]

	it('orders newest first, undated last', () => {
		expect(sortMessages(pool, 'received').map((m) => m.id)).toEqual([2, 1, 3])
	})

	it('orders by last processing when asked — a retried old message rises', () => {
		expect(sortMessages(pool, 'processed').map((m) => m.id)).toEqual([1, 3, 2])
	})

	it('does not mutate the input', () => {
		const copy = [...pool]
		sortMessages(pool, 'received')
		expect(pool).toEqual(copy)
	})
})

describe('messageDetails', () => {
	it('pairs the error with what the model actually returned', () => {
		const block = messageDetails(message({
			status: 'failed',
			error: 'No JSON object found in LLM response',
			lastResponse: '{"bookings": [{"type": "car_rental"}]}',
		}))
		expect(block).toContain('No JSON object found in LLM response')
		expect(block).toContain('--- Raw model response ---')
		expect(block).toContain('"type": "car_rental"')
	})

	it('says so explicitly when no response was recorded', () => {
		expect(messageDetails(message({ lastResponse: null }))).toContain('(no response recorded)')
	})

	it('offers nothing to expand on a clean row', () => {
		expect(hasDetails(message({ error: null, lastResponse: null }))).toBe(false)
		expect(hasDetails(message({ lastResponse: '{}' }))).toBe(true)
	})
})

describe('formatTimestamp', () => {
	it('renders an ATOM timestamp and passes through what it cannot parse', () => {
		expect(formatTimestamp('2026-08-14T10:00:00+00:00')).not.toBe('')
		expect(formatTimestamp(null)).toBe('')
		expect(formatTimestamp('not a date')).toBe('not a date')
	})
})
