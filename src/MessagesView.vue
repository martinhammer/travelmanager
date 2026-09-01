<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { fetchMessageBody, retryMessage } from './api'
import { type SortDirection, formatTimestamp, nextSortDirection, sortMarker } from './grid'
import {
	type MessageSort,
	MESSAGE_COLUMNS,
	filterMessagesByStatus,
	hasDetails,
	messageDetails,
	messageNotices,
	messageStatusLabel,
	retryable,
	sortMessages,
} from './messages'
import { isOpen, openDetail, route } from './navigation'
import { bookingsFromMessage } from './detail'
import { bookings, loading, messages, reload } from './store'

/**
 * Open the row the route points at, and bring it into view.
 *
 * Arriving here from the calendar's detail panel means the message is the thing
 * being looked at, and the row body is what this view has that the panel does
 * not — the prompt and the raw model response. It would be perverse to land on
 * the list with that still folded away.
 *
 * Nudged imperatively rather than bound with `:open`, so it stays a one-shot: a
 * binding would re-collapse the row the moment anything else re-rendered, and
 * would fight the user every time they closed it themselves.
 *
 * **On arrival only, not on every route change.** Within this view the summary
 * click already toggles the row and the subject click opens the panel — two
 * deliberately separate affordances, and expanding on selection would merge
 * them. App.vue swaps views without keep-alive, so arriving from anywhere else
 * mounts this component afresh and onMounted is exactly "you just got here".
 */
const rows = ref<HTMLElement | null>(null)

const revealRoutedMessage = async (): Promise<void> => {
	const detail = route.value.detail
	if (detail?.type !== 'message') {
		return
	}
	// The row may not exist yet on a fresh mount, or may not exist at all if a
	// filter excludes it — in which case there is nothing to reveal.
	await nextTick()
	const row = rows.value?.querySelector<HTMLDetailsElement>(`details[data-message="${detail.id}"]`)
	if (row === null || row === undefined) {
		return
	}
	row.open = true
	// 'nearest' so a row already on screen does not jump.
	row.scrollIntoView({ block: 'nearest' })
}

onMounted(revealRoutedMessage)

const filter = ref('all')
const sort = ref<MessageSort>('received')
const direction = ref<SortDirection>('desc')

const visible = computed(() => sortMessages(
	filterMessagesByStatus(messages.value, filter.value),
	sort.value,
	direction.value,
))

const columnLabels: Record<MessageSort, string> = {
	subject: t('travelmanager', 'Subject'),
	sender: t('travelmanager', 'From'),
	received: t('travelmanager', 'Date received'),
	processed: t('travelmanager', 'Last processed'),
	attempts: t('travelmanager', 'Attempts'),
	status: t('travelmanager', 'Status'),
}

const columns = MESSAGE_COLUMNS.map((column) => ({ key: column.key, label: columnLabels[column.key] }))

const onSortColumn = (column: MessageSort): void => {
	direction.value = nextSortDirection(MESSAGE_COLUMNS, column, sort.value, direction.value)
	sort.value = column
}

const filters: { key: string, label: string }[] = [
	{ key: 'all', label: t('travelmanager', 'All') },
	{ key: 'attention', label: t('travelmanager', 'Needs attention') },
	{ key: 'processed', label: t('travelmanager', 'Extracted') },
	{ key: 'related', label: t('travelmanager', 'Related') },
	{ key: 'no_booking', label: t('travelmanager', 'No booking') },
	{ key: 'processing', label: t('travelmanager', 'Waiting') },
]

const copyText = async (text: string) => {
	try {
		await navigator.clipboard.writeText(text)
		showSuccess(t('travelmanager', 'Copied to clipboard'))
	} catch (e) {
		showError(t('travelmanager', 'Could not copy to clipboard'))
	}
}

// Retained email bodies, keyed by message id and fetched on first expand: the
// list response deliberately omits them, and loading every one up front would
// pull up to 20000 chars per row for text nobody has asked to see.
const rawBodies = ref<Record<number, string>>({})

const rawBody = (id: number): string => rawBodies.value[id] ?? t('travelmanager', 'Loading…')

const onRawMessageToggle = async (id: number, event: Event) => {
	const open = (event.target as HTMLDetailsElement).open
	if (!open || rawBodies.value[id] !== undefined) {
		return
	}
	try {
		const body = await fetchMessageBody(id)
		rawBodies.value[id] = body || t('travelmanager', '(no body retained)')
	} catch (e) {
		// Kept in the box rather than raised as a toast: the failure belongs to
		// this one section, and a toast would leave "Loading…" sitting there.
		rawBodies.value[id] = t('travelmanager', 'Could not load the message body.')
	}
}

const onRetry = async (id: number) => {
	try {
		await retryMessage(id)
		// The model answers asynchronously, so the row will not settle until a
		// later reload — say so rather than implying it is already done.
		showSuccess(t('travelmanager', 'Extraction re-scheduled — refresh in a moment to see the result'))
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not re-run the extraction'))
	}
}
</script>

<template>
	<div class="tm-content">
		<div class="tm-toolbar">
			<h2 class="tm-toolbar-heading">
				{{ t('travelmanager', 'Messages') }}
			</h2>
		</div>
		<div class="tm-chips">
			<NcButton v-for="chip in filters"
				:key="chip.key"
				:variant="filter === chip.key ? 'primary' : 'tertiary'"
				@click="filter = chip.key">
				{{ chip.label }}
			</NcButton>
		</div>
		<NcEmptyContent v-if="!loading && visible.length === 0"
			:name="messages.length === 0 ? t('travelmanager', 'Nothing ingested yet') : t('travelmanager', 'Nothing matches this filter')"
			:description="messages.length === 0
				? t('travelmanager', 'Emails read from your travel mailbox will be listed here.')
				: t('travelmanager', 'Try a different filter to see the other messages.')" />

		<div v-if="visible.length > 0" :class="['tm-row-summary', 'tm-grid-header', $style.columns]">
			<span aria-hidden="true" />
			<button v-for="column in columns"
				:key="column.key"
				type="button"
				:class="['tm-column-heading', { 'tm-column-heading-active': sort === column.key }]"
				@click="onSortColumn(column.key)">
				<span class="tm-heading-label">{{ column.label }}</span>
				<span class="tm-sort-marker" aria-hidden="true">
					{{ sortMarker(sort, column.key, direction) }}
				</span>
			</button>
		</div>
		<!-- Dropped entirely when empty, not just left without rows: its own
		     top/bottom rules would otherwise collapse into a stray line. -->
		<ol v-if="visible.length > 0" ref="rows" class="tm-rows">
			<li v-for="item in visible" :key="item.id">
				<details :data-message="item.id"
					:class="['tm-row', { 'tm-row-selected': isOpen('message', item.id) }]">
					<summary :class="['tm-row-summary', $style.columns]">
						<svg class="tm-chevron"
							viewBox="0 0 24 24"
							width="16"
							height="16"
							aria-hidden="true">
							<path d="M9 5l7 7-7 7"
								fill="none"
								stroke="currentColor"
								stroke-width="2"
								stroke-linecap="round"
								stroke-linejoin="round" />
						</svg>
						<button type="button"
							class="tm-cell-text tm-open-link"
							@click.stop.prevent="openDetail('message', item.id)">
							{{ item.subject || t('travelmanager', '(no subject)') }}
						</button>
						<span class="tm-cell-text">{{ item.sender }}</span>
						<span class="tm-cell-meta">{{ formatTimestamp(item.sentAt) }}</span>
						<span class="tm-cell-meta">{{ formatTimestamp(item.processedAt) }}</span>
						<span class="tm-cell-meta">{{ item.attempts }}</span>
						<span :class="['tm-badge', 'tm-cell-status', {
							'tm-badge-warning': item.status === 'failed' || item.status === 'dropped',
						}]">
							{{ messageStatusLabel(item.status) }}
						</span>
					</summary>
					<div class="tm-row-body">
						<!-- No metadata repeated here: the grid row above already carries
						     From/dates/attempts. What the body adds is what does not fit a
						     column — why it failed, and the retry. -->
						<NcNoteCard v-for="(notice, i) in messageNotices(item, bookingsFromMessage(bookings, item))"
							:key="i"
							class="tm-notice"
							:type="notice.type"
							:text="notice.text" />
						<!-- The two halves of a diagnosis: what we sent the model, and what
						     it sent back. Both start collapsed — the body is fetched only
						     once its section is opened. -->
						<details v-if="item.canRetry"
							:class="$style.section"
							@toggle="onRawMessageToggle(item.id, $event)">
							<summary>{{ t('travelmanager', 'Raw message') }}</summary>
							<div :class="$style.textBox">
								<!-- Wrapper, not the button itself: NcButton's own
								     `position: relative` is the same specificity as ours and its
								     stylesheet loads later, so it would win. -->
								<span :class="$style.copyButton">
									<NcButton variant="secondary" @click="copyText(rawBody(item.id))">
										{{ t('travelmanager', 'Copy') }}
									</NcButton>
								</span>
								<pre :class="$style.text">{{ rawBody(item.id) }}</pre>
							</div>
						</details>
						<!-- Long, so kept out of the way — but opened by default when
						     something went wrong, since that is why you came here. -->
						<details v-if="hasDetails(item)"
							:class="$style.section"
							:open="item.status === 'failed' || item.status === 'dropped' || item.status === 'related'">
							<summary>{{ t('travelmanager', 'Model response') }}</summary>
							<div :class="$style.textBox">
								<span :class="$style.copyButton">
									<NcButton variant="secondary" @click="copyText(messageDetails(item))">
										{{ t('travelmanager', 'Copy') }}
									</NcButton>
								</span>
								<pre :class="$style.text">{{ messageDetails(item) }}</pre>
							</div>
						</details>
						<div class="tm-actions">
							<!-- Shown whenever a retry is possible in principle, disabled while
							     one is in flight: a control that vanishes is more confusing
							     than one that greys out with a reason. -->
							<NcButton v-if="item.canRetry"
								variant="secondary"
								:disabled="!retryable(item)"
								@click="onRetry(item.id)">
								{{ t('travelmanager', 'Retry extraction') }}
							</NcButton>
							<span v-if="!item.canRetry" class="tm-meta">
								{{ t('travelmanager', 'The email text is no longer retained, so this cannot be re-run.') }}
							</span>
						</div>
					</div>
				</details>
			</li>
		</ol>
	</div>
</template>

<style module>
/* Subject and From share the flexible space 3:2 — the subject is the longer
   string and the one you read; the sender only needs to be recognisable. */
.columns {
	grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 180px 180px 80px 200px;
}

/* Columns drop as space runs out, least-scanned first. The expanded row body no
   longer repeats this metadata, so a dropped column is genuinely not shown at
   that width — only Attempts survives, inside the Model response text. */
@media (max-width: 1100px) {
	.columns {
		grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 180px 200px;
	}

	/* Last processed, Attempts. */
	.columns > *:nth-child(5),
	.columns > *:nth-child(6) {
		display: none;
	}
}

@media (max-width: 800px) {
	.columns {
		grid-template-columns: 16px minmax(0, 1fr) auto;
	}

	/* From, Date received — the subject and the status are what you scan for. */
	.columns > *:nth-child(3),
	.columns > *:nth-child(4) {
		display: none;
	}
}

.section {
	margin: 8px 0 0;
	font-size: 0.9em;
}

/* Positioning context for the copy button, which floats over the text rather
   than sitting above it — one less row of chrome between you and the content. */
.textBox {
	position: relative;
}

/* Pinned to the box, not the text, so it stays put while the content scrolls
   under it. Inset clears the pre's own scrollbar. */
.copyButton {
	position: absolute;
	inset-block-start: 12px;
	inset-inline-end: 16px;
	z-index: 1;
}

.text {
	margin: 8px 0 0;
	padding: 8px;
	/* Room at the end of the first line so the button never lands on text. */
	padding-inline-end: 72px;
	max-height: 300px;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
