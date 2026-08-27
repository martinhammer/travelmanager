import { describe, expect, it } from 'vitest'
import type { Message } from '../../src/api'
import {
	filterMessagesByStatus,
	hasDetails,
	messageDetails,
	messageNotices,
	messageStatusLabel,
	needsAttention,
	retryable,
	sortMessages,
} from '../../src/messages'

const message = (overrides: Partial<Message> = {}): Message => ({
	id: 1,
	mailbox: 'INBOX',
	messageId: '<abc@example.com>',
	subject: 'Your booking',
	sender: 'KLM <noreply@klm.com>',
	status: 'processed',
	failureKind: null,
	issueReasons: [],
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

describe('messageNotices', () => {
	it('explains a related row as information, not a fault', () => {
		const [notice] = messageNotices(message({ status: 'related' }))
		expect(notice.type).toBe('info')
		expect(notice.text).toContain('does not apply updates yet')
	})

	it('is not counted as needing attention — a notice you cannot clear', () => {
		expect(needsAttention([message({ status: 'related' })])).toEqual([])
	})

	it('warns rather than informs when bookings were rejected', () => {
		expect(messageNotices(message({ status: 'dropped' }))[0].type).toBe('warning')
	})

	it('reassures on an email that genuinely held no booking', () => {
		expect(messageNotices(message({ status: 'no_booking' }))[0].type).toBe('info')
	})

	it('reports a classified failure as an error', () => {
		const [notice] = messageNotices(message({ status: 'failed', failureKind: 'provider' }))
		expect(notice.type).toBe('error')
		expect(notice.text).toBe('No response from AI provider')
	})

	it('says nothing for a clean extraction', () => {
		expect(messageNotices(message({ status: 'processed' }))).toEqual([])
	})

	it('surfaces a repaired response even on a successful row', () => {
		// The whole point: a repair is invisible in the status, and a rising
		// repair rate is the early warning of a degrading model.
		const notices = messageNotices(message({ status: 'processed', issueReasons: ['repaired_json'] }))
		expect(notices).toHaveLength(1)
		expect(notices[0].type).toBe('warning')
		expect(notices[0].text).toContain('repaired')
	})

	it('stacks a failure and a repair, most urgent first', () => {
		const notices = messageNotices(message({
			status: 'dropped',
			failureKind: 'validation',
			issueReasons: ['repaired_json', 'missing_departure'],
		}))
		expect(notices.map((n) => n.type)).toEqual(['error', 'warning', 'warning'])
	})

	it('ignores issue reasons that carry no notice of their own', () => {
		expect(messageNotices(message({ issueReasons: ['partial_segments'] }))).toEqual([])
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
		expect(sortMessages(pool, 'received', 'desc').map((m) => m.id)).toEqual([2, 1, 3])
	})

	it('orders by last processing when asked — a retried old message rises', () => {
		expect(sortMessages(pool, 'processed', 'desc').map((m) => m.id)).toEqual([1, 3, 2])
	})

	it('reverses on ascending', () => {
		expect(sortMessages(pool, 'received', 'asc').map((m) => m.id)).toEqual([1, 2, 3])
	})

	it('keeps valueless rows last in both directions — they are unsortable, not smallest', () => {
		expect(sortMessages(pool, 'received', 'asc').at(-1)?.id).toBe(3)
		expect(sortMessages(pool, 'received', 'desc').at(-1)?.id).toBe(3)
	})

	it('sorts text case-insensitively, blanks last', () => {
		const senders = [
			message({ id: 1, sender: 'united' }),
			message({ id: 2, sender: null }),
			message({ id: 3, sender: 'KLM' }),
		]
		expect(sortMessages(senders, 'sender', 'asc').map((m) => m.id)).toEqual([3, 1, 2])
	})

	it('sorts attempts numerically, not as strings', () => {
		const tries = [message({ id: 1, attempts: 9 }), message({ id: 2, attempts: 10 })]
		expect(sortMessages(tries, 'attempts', 'desc').map((m) => m.id)).toEqual([2, 1])
	})

	it('sorts status by the label on screen, not the raw slug', () => {
		// 'failed' sorts after 'no_booking' as a slug, but its label ("Failed")
		// sorts before "No booking".
		const statuses = [message({ id: 1, status: 'no_booking' }), message({ id: 2, status: 'failed' })]
		expect(sortMessages(statuses, 'status', 'asc').map((m) => m.id)).toEqual([2, 1])
	})

	it('does not mutate the input', () => {
		const copy = [...pool]
		sortMessages(pool, 'received', 'desc')
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

