import type { Message } from './api'

/**
 * Human labels for the ingestion ledger's statuses. The wording matters here:
 * "no booking found" and "all bookings rejected" both produced nothing, but
 * only the second is the app's fault and worth retrying.
 */
const STATUS_LABELS: Record<string, string> = {
	processing: 'Waiting for the model',
	processed: 'Bookings extracted',
	no_booking: 'No booking in this email',
	related: 'Relates to a booking you have',
	dropped: 'Bookings found but rejected',
	failed: 'Extraction failed',
}

/**
 * Label for a message status, falling back to the raw value so an unknown
 * status from a newer backend still renders as something.
 * @param status the stored status string
 */
export const messageStatusLabel = (status: string): string => STATUS_LABELS[status] ?? status

/**
 * Whether a retry can be performed *right now* — drives the button's enabled
 * state, while `canRetry` alone decides whether it is shown at all. Retrying
 * while a task is in flight would only queue a duplicate.
 * @param message the ledger row
 */
export const retryable = (message: Message): boolean =>
	message.canRetry && message.status !== 'processing'

/**
 * Messages where the app failed to get a booking out of an email that may well
 * have contained one — the rows worth a human's attention.
 * @param items the ledger rows
 */
export const needsAttention = (items: Message[]): Message[] =>
	items.filter((m) => m.status === 'failed' || m.status === 'dropped')

/**
 * Filter the ledger by status. Beyond the real statuses it accepts two
 * sentinels: 'all', and 'attention' for the failed/dropped rows a human should
 * look at — the reason someone opens this view in the first place.
 * @param items the ledger rows
 * @param status the status to keep, 'attention', or 'all' for no filtering
 */
export const filterMessagesByStatus = (items: Message[], status: string): Message[] => {
	if (status === 'all') {
		return items
	}
	if (status === 'attention') {
		return needsAttention(items)
	}
	return items.filter((m) => m.status === status)
}

/** How the ingestion ledger is ordered. */
export type MessageSort = 'received' | 'processed'

/**
 * Order ledger rows, newest first either way. 'received' follows the email's own
 * date, 'processed' follows when we last ran it — which differ once a retry
 * re-runs an old message.
 * @param items the rows to order (not mutated)
 * @param sort the ordering to apply
 */
export const sortMessages = (items: Message[], sort: MessageSort): Message[] => {
	const key = sort === 'received' ? 'sentAt' : 'processedAt'
	return [...items].sort((a, b) => {
		const av = a[key]
		const bv = b[key]
		if (av === bv) {
			return 0
		}
		if (!av) {
			return 1
		}
		if (!bv) {
			return -1
		}
		return av < bv ? 1 : -1
	})
}

/**
 * Format an ATOM timestamp for display in the viewer's own locale/timezone.
 * Unlike booking times (local wall-clock at the destination, never converted —
 * see V8), these are real instants, so converting is correct.
 * @param value the ATOM timestamp, or null
 */
export const formatTimestamp = (value: string | null): string => {
	if (!value) {
		return ''
	}
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return value
	}
	return date.toLocaleString()
}

/**
 * The self-contained troubleshooting block for a message: what went wrong next
 * to what the model actually returned. Rendered *and* copied from one string, so
 * what lands on the clipboard is exactly what was on screen.
 * @param message the ledger row
 */
export const messageDetails = (message: Message): string => {
	const lines = [
		`Status: ${messageStatusLabel(message.status)}`,
		`Source message: ${message.messageId}`,
		`Attempts: ${message.attempts}`,
	]
	if (message.error) {
		// The same field carries a failure reason on a failed row and notes
		// (e.g. "the response was repaired") on a successful one — label it for
		// what it actually is, so a clean extraction does not read as an error.
		const heading = message.status === 'failed' || message.status === 'dropped' ? 'Error' : 'Notes'
		// 'related' rows carry a notice, not a fault — see failureKindLabel.
		lines.push('', `--- ${heading} ---`, message.error)
	}
	// The response is the half you tune the prompt against; without it an error
	// like "No JSON object found" says nothing about what the model did.
	lines.push('', '--- Raw model response ---', message.lastResponse || '(no response recorded)')
	return lines.join('\n')
}

/**
 * Whether there is anything worth expanding for this row.
 * @param message the ledger row
 */
export const hasDetails = (message: Message): boolean =>
	Boolean(message.error) || Boolean(message.lastResponse)

/**
 * A notice for outcomes that are not faults but still need explaining. Kept
 * apart from failureKindLabel so an informational row never reads as an error.
 * @param message the ledger row
 */
export const statusNotice = (message: Message): string =>
	message.status === 'related'
		? 'This email is about a booking you already have. Travel Manager creates a booking from one email only and does not apply updates yet, so nothing was changed.'
		: ''

/**
 * Why a failure happened, in words, when the backend classified it.
 * @param message the ledger row
 */
export const failureKindLabel = (message: Message): string => {
	switch (message.failureKind) {
	case 'provider':
		return 'The AI provider failed to answer — retrying usually helps'
	case 'validation':
		return 'The model answered, but the response could not be parsed'
	case 'schedule':
		return 'The extraction task could not be scheduled'
	default:
		return ''
	}
}
