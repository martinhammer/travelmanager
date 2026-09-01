import type { Booking, Message } from './api'
import type { SortColumn, SortDirection } from './grid'

/**
 * Human labels for the ingestion ledger's statuses. The wording matters here:
 * "no booking found" and "all bookings rejected" both produced nothing, but
 * only the second is the app's fault and worth retrying.
 */
const STATUS_LABELS: Record<string, string> = {
	processing: 'Submitted',
	processed: 'Bookings extracted',
	no_booking: 'No booking',
	related: 'Existing booking',
	dropped: 'Rejected',
	failed: 'Failed',
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

/** The grid's sortable columns — one per column heading. */
export type MessageSort = 'sender' | 'subject' | 'received' | 'processed' | 'attempts' | 'status'

/** The Messages grid's columns, in display order. See SortColumn in ./grid. */
export const MESSAGE_COLUMNS: SortColumn<MessageSort>[] = [
	// Subject leads: the first column is the one that opens the sidebar, and it
	// should be the row's own identity in every grid.
	{ key: 'subject', defaultDirection: 'asc' },
	{ key: 'sender', defaultDirection: 'asc' },
	{ key: 'received', defaultDirection: 'desc' },
	{ key: 'processed', defaultDirection: 'desc' },
	{ key: 'attempts', defaultDirection: 'desc' },
	{ key: 'status', defaultDirection: 'asc' },
]

/**
 * The value a column sorts on. Strings sort case-insensitively so "KLM" and
 * "klm" land together; status sorts on the label shown, not the raw slug, so the
 * order matches what is on screen.
 * @param message the ledger row
 * @param sort the column to read
 */
const sortValue = (message: Message, sort: MessageSort): string | number | null => {
	switch (sort) {
	case 'sender':
		return message.sender?.toLowerCase() || null
	case 'subject':
		return message.subject?.toLowerCase() || null
	case 'received':
		return message.sentAt
	case 'processed':
		return message.processedAt
	case 'attempts':
		return message.attempts
	case 'status':
		return messageStatusLabel(message.status).toLowerCase()
	}
}

/**
 * Order ledger rows by one column. Rows with no value for that column always
 * sink to the bottom, in both directions: a message with no sender is not
 * "smallest", it is simply unsortable, and flipping it to the top would bury the
 * rows you asked to see.
 * @param items the rows to order (not mutated)
 * @param sort the column to order by
 * @param direction 'asc' or 'desc'
 */
export const sortMessages = (items: Message[], sort: MessageSort, direction: SortDirection = 'desc'): Message[] => {
	const sign = direction === 'asc' ? 1 : -1
	return [...items].sort((a, b) => {
		const av = sortValue(a, sort)
		const bv = sortValue(b, sort)
		if (av === bv) {
			return 0
		}
		if (av === null || av === '') {
			return 1
		}
		if (bv === null || bv === '') {
			return -1
		}
		return av < bv ? -sign : sign
	})
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

/** One note card in an expanded row. `type` maps to NcNoteCard's own types. */
export interface MessageNotice {
	type: 'info' | 'warning' | 'error'
	text: string
}

/**
 * Why a failure happened, when the backend classified it. Separate from the
 * outcome notices below so a fault never renders as an informational note.
 * @param message the ledger row
 */
const failureNotice = (message: Message): MessageNotice | null => {
	switch (message.failureKind) {
	case 'provider':
		return { type: 'error', text: 'No response from AI provider' }
	case 'validation':
		return { type: 'error', text: 'Model response could not be parsed' }
	case 'schedule':
		return { type: 'error', text: 'The extraction task could not be scheduled' }
	default:
		return null
	}
}

/**
 * Why a run that did not fail still produced no booking — or produced one from a
 * response we had to touch first. Every zero-booking outcome gets a note: the
 * status badge alone says *what* happened, not whether it needs you.
 * @param message the ledger row
 */
const outcomeNotice = (message: Message): MessageNotice | null => {
	switch (message.status) {
	case 'related':
		return {
			type: 'info',
			text: 'This email is about an existing booking. Travel Manager creates a booking from one email only and does not apply updates yet, so nothing was changed.',
		}
	case 'no_booking':
		return {
			type: 'info',
			text: 'The model read this email and found no travel booking in it.',
		}
	case 'dropped':
		return {
			type: 'warning',
			text: 'The model reported a booking, but it could not be validated, so nothing was saved.',
		}
	default:
		return null
	}
}

/**
 * Notes to show above the detail sections of an expanded row, most urgent first.
 * A row can warrant more than one: a booking can be saved *and* have come from a
 * response we repaired.
 * @param message the ledger row
 * @param created the bookings this message produced (see bookingsFromMessage)
 */
export const messageNotices = (message: Message, created: Booking[] = []): MessageNotice[] => {
	const notices = [failureNotice(message), outcomeNotice(message)]

	// Read off the booking this run produced, not off the message: a possible
	// duplicate is a relation between two bookings, and the message is only where
	// it was noticed. That also gets the asymmetry right — this row is the run
	// that made the second booking, so it is the run whose outcome needs
	// checking. The earlier email's row records a run that did nothing wrong, and
	// a banner added to it after the fact would describe something that had not
	// happened yet. Both bookings still show the flag on their own cards.
	if (created.some((booking) => booking.possibleDuplicateOf !== null)) {
		notices.push({
			type: 'warning',
			text: 'This email may duplicate a booking you already have. Both were kept, so check them and discard whichever is wrong.',
		})
	}

	// Not tied to a status: a repair can accompany any outcome, including a
	// perfectly good extraction. A rising repair rate is how a degrading model
	// shows up before it starts failing outright, so it is never silent.
	if (message.issueReasons.includes('repaired_json')) {
		notices.push({
			type: 'warning',
			text: 'The model returned malformed JSON, which Travel Manager repaired before reading it.',
		})
	}

	return notices.filter((n): n is MessageNotice => n !== null)
}
