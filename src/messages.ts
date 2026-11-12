import type { Booking, Message, MessageAttachment } from './api'
import type { SortColumn, SortDirection } from './grid'

/**
 * Human labels for the ingestion ledger's statuses. The wording matters here:
 * "no booking found" and "all bookings rejected" both produced nothing, but
 * only the second is the app's fault and worth retrying.
 */
const STATUS_LABELS: Record<string, string> = {
	queued: 'Queued',
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
 * Statuses where the message is still on its way to a result: queued behind the
 * extraction limits, or sent and awaiting the model's answer.
 */
const WAITING_STATUSES = ['queued', 'processing']

/**
 * Whether a retry can be performed *right now* — drives the button's enabled
 * state, while `canRetry` alone decides whether it is shown at all. Retrying a
 * message that is still waiting would put a second attempt in flight for one
 * email, and the backend refuses it for that reason.
 * @param message the ledger row
 */
export const retryable = (message: Message): boolean =>
	message.canRetry && !WAITING_STATUSES.includes(message.status)

/**
 * Statuses where the app failed to get a booking out of an email that may well
 * have contained one. A fact about the pipeline, so it is the *unfiltered* half
 * of the rule — see needsAttention for the user's half.
 */
const ATTENTION_STATUSES = ['failed', 'dropped']

/**
 * Whether this row is one the app could not extract a booking from — regardless
 * of whether the user has since discarded it. This is what decides that a
 * Discard button is worth offering at all, and it has to stay true *after* the
 * button is pressed, or the control that undoes the action would disappear along
 * with the action.
 * @param message the ledger row
 */
export const unresolved = (message: Message): boolean =>
	ATTENTION_STATUSES.includes(message.status)

/**
 * The rows worth a human's attention: the app failed to get a booking out of the
 * email, **and** the user has not discarded the row.
 *
 * Two axes, exactly as a booking has: `status` is what the pipeline observed and
 * `discarded` is what the user decided about it. A correct refusal — an email
 * genuinely about travel that carries no departure time — would otherwise sit in
 * this count for ever, which is how a counter gets ignored.
 * @param items the ledger rows
 */
export const needsAttention = (items: Message[]): Message[] =>
	items.filter((m) => unresolved(m) && !m.discarded)

/**
 * Filter the ledger by status. Beyond the real statuses it accepts sentinels:
 * 'all'; 'attention' for the failed/dropped rows a human should look at — the
 * reason someone opens this view in the first place; 'waiting' for everything
 * not yet answered, queued or sent; and 'discarded'.
 * @param items the ledger rows
 * @param status the status to keep, or one of the sentinels above
 */
export const filterMessagesByStatus = (items: Message[], status: string): Message[] => {
	if (status === 'all') {
		return items
	}
	if (status === 'attention') {
		return needsAttention(items)
	}
	// Discarded rows stay in every other filter, including their own status: the
	// ledger is a record of what happened, and discarding changes what the app
	// asks of you, not what it did. This chip is how you find them again.
	if (status === 'discarded') {
		return items.filter((m) => m.discarded)
	}
	// One chip for both: from the user's side, "queued" and "sent" are the same
	// answer to the question they came with — it has not been read yet.
	if (status === 'waiting') {
		return items.filter((m) => WAITING_STATUSES.includes(m.status))
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
	// Never sent: an empty prompt only earns a confident "no booking", which
	// would read as the model's judgement rather than our failure to read it.
	case 'no_text':
		return { type: 'error', text: 'No readable text was found in this email, so it was not sent to the model' }
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
// Info, never warning: the row is settled, and re-raising the alarm on something
// the user has already answered is how a notice area stops being read.
const discardedNotice = (message: Message): MessageNotice | null => message.discarded
	? {
		type: 'info',
		text: 'You discarded this message, so it no longer counts as needing attention. Its record is kept, which is also what stops the email being ingested again.',
	}
	: null

const outcomeNotice = (message: Message): MessageNotice | null => {
	switch (message.status) {
	// Says *why* it waits, because a queued row with no reason reads as stuck.
	case 'queued':
		return {
			type: 'info',
			text: 'This email is waiting to be sent to the model. Extractions are released a few at a time so the AI provider is not overwhelmed, and this one goes as soon as its turn comes.',
		}
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

const attachmentName = (attachment: MessageAttachment): string =>
	attachment.filename === null ? 'An unnamed PDF' : `"${attachment.filename}"`

const pageCount = (attachment: MessageAttachment): string =>
	attachment.pages === 1 ? '1 page' : `${attachment.pages ?? 0} pages`

/**
 * Whether any attachment's text went to the model with this email.
 * @param message the ledger row
 */
export const readAttachments = (message: Message): MessageAttachment[] =>
	message.attachments.filter((attachment) => attachment.outcome === 'read')

/**
 * What became of each PDF attachment. One note for everything that was read,
 * then one per file that was not: a skipped file that leaves no trace makes the
 * email look as though it never had one.
 *
 * Warnings are kept for what the user can do something about or ought to know
 * went wrong; the admin's switch is a decision, not a fault, so it is info.
 * @param message the ledger row
 */
const attachmentNotices = (message: Message): MessageNotice[] => {
	const notices: MessageNotice[] = []
	const read = readAttachments(message)
	if (read.length > 0) {
		const files = read.map((a) => `${a.filename ?? 'unnamed PDF'} (${pageCount(a)})`).join(', ')
		notices.push({
			type: 'info',
			text: read.length === 1
				? `The text of 1 PDF attachment was sent to the model with this email: ${files}.`
				: `The text of ${read.length} PDF attachments was sent to the model with this email: ${files}.`,
		})
		for (const attachment of read.filter((a) => a.truncated)) {
			notices.push({
				type: 'info',
				text: attachment.pagesRead !== null && attachment.pages !== null && attachment.pagesRead < attachment.pages
					? `Only the first ${attachment.pagesRead} of ${attachment.pages} pages of ${attachmentName(attachment)} were read.`
					: `${attachmentName(attachment)} is long, so only the start of its text was sent.`,
			})
		}
	}

	for (const attachment of message.attachments) {
		const name = attachmentName(attachment)
		switch (attachment.outcome) {
		case 'no_text':
			notices.push({ type: 'warning', text: `${name} has no text, so it was not read. It is probably a scanned document; scans are not read.` })
			break
		case 'encrypted':
			notices.push({ type: 'warning', text: `${name} is encrypted and could not be opened, so it was not read.` })
			break
		case 'failed':
			notices.push({ type: 'warning', text: `${name} could not be read. The activity log has the details.` })
			break
		case 'crashed':
			notices.push({ type: 'warning', text: `Reading ${name} stopped a mailbox read, so this email's PDF attachments were skipped to let the rest of the mail through.` })
			break
		case 'too_large':
			notices.push({ type: 'warning', text: `${name} is larger than 5 MB, so it was not read.` })
			break
		case 'too_many':
			notices.push({ type: 'warning', text: `${name} was not read: only the first 3 PDF attachments of an email are.` })
			break
		case 'disabled':
			notices.push({ type: 'info', text: `${name} was not read, because reading PDF attachments is turned off by the administrator.` })
			break
		}
	}
	return notices
}

/**
 * Notes to show above the detail sections of an expanded row, most urgent first.
 * A row can warrant more than one: a booking can be saved *and* have come from a
 * response we repaired.
 * @param message the ledger row
 * @param created the bookings this message produced (see bookingsFromMessage)
 */
export const messageNotices = (message: Message, created: Booking[] = []): MessageNotice[] => {
	// The discard sits directly after the outcome it answers: the failure is still
	// described in full (that is what prompt tuning reads), and this says what the
	// user decided about it.
	const notices = [failureNotice(message), outcomeNotice(message), discardedNotice(message), ...attachmentNotices(message)]

	// Read off the booking this run produced, not off the message: a possible
	// duplicate is a relation between bookings, and the message is only where it
	// was noticed.
	//
	// Shown on *every* email of a group, not just the one that arrived last. It
	// used to be the last one alone — that email's booking carried the edge and
	// the earlier one did not — but group membership is symmetric, and which
	// email came second is an accident of arrival order the reader has no way to
	// know. The wording says the booking may be a duplicate rather than that this
	// email duplicated something, so it is true from either side.
	if (created.some((booking) => booking.duplicateGroupId !== null)) {
		notices.push({
			type: 'warning',
			text: 'A booking from this email may be the same as one from another email. Compare them and discard whichever is wrong.',
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

	// Also not tied to a status: what *was* read may have made a booking, so the
	// row can be processed and still be missing the rest. Retrying is how to get
	// it, which is why it warns rather than informs.
	if (message.issueReasons.includes('incomplete_json')) {
		notices.push({
			type: 'warning',
			text: 'Part of the model\'s response was cut off or could not be read, so bookings from this email may be missing. Retry the extraction to try again.',
		})
	}

	return notices.filter((n): n is MessageNotice => n !== null)
}
