<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { Booking, ReviewState } from './api'
import { deleteBooking, setBookingReviewState } from './api'
import BookingDetails from './BookingDetails.vue'
import TripPickerDialog from './TripPickerDialog.vue'
import {
	type BookingSort,
	BOOKING_COLUMNS,
	bookingSpan,
	filterBookings,
	reviewActions,
	sortBookings,
} from './bookings'
import { type SortDirection, formatTimestamp, nextSortDirection, sortMarker } from './grid'
import { actionLabel, bookingStatusLabel, reviewLabels, reviewStateLabel, typeName } from './labels'
import { isOpen, openDetail } from './navigation'
import { availableTypes, bookings, loading, reload, tripNames } from './store'

// This view's own filter/sort state. 'travel' ascending is the default: what is
// coming up next, first.
const filter = ref('all')
const type = ref('all')
const sort = ref<BookingSort>('travel')
const direction = ref<SortDirection>('asc')

const filtered = computed(() => sortBookings(
	filterBookings(bookings.value, filter.value, type.value),
	sort.value,
	direction.value,
	new Date(),
	tripNames.value,
))

const tripNameFor = (tripId: number | null): string =>
	tripId === null ? '' : (tripNames.value[tripId] ?? '')

// Literal t() calls so the strings are extractable; order and default direction
// come from BOOKING_COLUMNS so the two cannot drift apart.
const columnLabels: Record<BookingSort, string> = {
	title: t('travelmanager', 'Title'),
	trip: t('travelmanager', 'Trip'),
	type: t('travelmanager', 'Type'),
	provider: t('travelmanager', 'Provider'),
	reference: t('travelmanager', 'Reference'),
	travel: t('travelmanager', 'Travel dates'),
	added: t('travelmanager', 'Added'),
	reviewState: t('travelmanager', 'Status'),
}

const columns = BOOKING_COLUMNS.map((column) => ({ key: column.key, label: columnLabels[column.key] }))

const onSortColumn = (column: BookingSort): void => {
	direction.value = nextSortDirection(BOOKING_COLUMNS, column, sort.value, direction.value)
	sort.value = column
}

const filters: { key: string, label: string }[] = [
	{ key: 'all', label: t('travelmanager', 'All') },
	{ key: 'draft', label: t('travelmanager', 'Drafts') },
	{ key: 'confirmed', label: t('travelmanager', 'Confirmed') },
	{ key: 'archived', label: t('travelmanager', 'Archived') },
	{ key: 'discarded', label: t('travelmanager', 'Discarded') },
]

// --- review actions -------------------------------------------------------

const onReview = async (id: number, target: ReviewState) => {
	try {
		await setBookingReviewState(id, target)
		showSuccess(reviewLabels[target].done)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not update the booking'))
	}
}

/**
 * Confirming a *draft* asks which trip it belongs to first; every other review
 * transition applies straight away. Restoring a discarded booking also targets
 * 'confirmed', but it is not a first decision and may already have a trip — hence
 * the reviewState check rather than a target check alone.
 * @param item the booking being acted on
 * @param target the review state the button moves it to
 */
const onReviewAction = (item: Booking, target: ReviewState) => {
	if (target === 'confirmed' && item.reviewState === 'draft') {
		openTripPicker(item)
		return
	}
	onReview(item.id, target)
}

/**
 * Archiving and discarding are never the obvious next thing to do, so they never
 * take the primary slot; confirming and restoring are.
 * @param target the review state the button moves the booking to
 */
const reviewVariant = (target: ReviewState): 'primary' | 'secondary' =>
	target === 'archived' || target === 'discarded' ? 'secondary' : 'primary'

const pickerOpen = ref(false)
const pickerTarget = ref<Booking | null>(null)

const openTripPicker = (item: Booking) => {
	pickerTarget.value = item
	pickerOpen.value = true
}

// --- permanent deletion ---------------------------------------------------

const deleteOpen = ref(false)
const deleteTarget = ref<Booking | null>(null)

const askDelete = (item: Booking) => {
	deleteTarget.value = item
	deleteOpen.value = true
}

const confirmDelete = async () => {
	if (deleteTarget.value === null) {
		return
	}
	try {
		await deleteBooking(deleteTarget.value.id)
		showSuccess(t('travelmanager', 'Booking deleted'))
		deleteOpen.value = false
		deleteTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the booking'))
	}
}
</script>

<template>
	<div class="tm-content">
		<div class="tm-toolbar">
			<h2 class="tm-toolbar-heading">
				{{ t('travelmanager', 'Bookings') }}
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
		<div v-if="availableTypes.length > 1" class="tm-chips">
			<NcButton :variant="type === 'all' ? 'secondary' : 'tertiary'" @click="type = 'all'">
				{{ t('travelmanager', 'All types') }}
			</NcButton>
			<NcButton v-for="value in availableTypes"
				:key="value"
				:variant="type === value ? 'secondary' : 'tertiary'"
				@click="type = value">
				{{ typeName(value) }}
			</NcButton>
		</div>
		<NcEmptyContent v-if="!loading && filtered.length === 0"
			:name="bookings.length === 0 ? t('travelmanager', 'Nothing here yet') : t('travelmanager', 'Nothing matches these filters')"
			:description="bookings.length === 0
				? t('travelmanager', 'Travel bookings extracted from your mailbox will appear here as drafts.')
				: t('travelmanager', 'Try a different filter to see your other bookings.')" />

		<!-- A grid, not a <table>: each row is a native <details> so the disclosure
		     is keyboard-operable and carries no open/closed state of its own, which
		     a table's row-pair markup cannot do. The headings are therefore plain
		     buttons — table ARIA would promise a structure the markup lacks. -->
		<div v-if="filtered.length > 0" :class="['tm-row-summary', 'tm-grid-header', $style.columns]">
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
		<ol v-if="filtered.length > 0" class="tm-rows">
			<li v-for="item in filtered" :key="item.id">
				<details :class="['tm-row', {
					'tm-muted': item.reviewState === 'discarded' || item.reviewState === 'archived',
					'tm-row-selected': isOpen('booking', item.id),
				}]">
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
							@click.stop.prevent="openDetail('booking', item.id)">
							{{ item.title || typeName(item.type) }}
						</button>
						<span class="tm-cell-text">{{ tripNameFor(item.tripId) }}</span>
						<!-- A lozenge, matching the type lozenges on a trip row. -->
						<span class="tm-badges tm-cell-status">
							<span class="tm-badge">{{ typeName(item.type) }}</span>
						</span>
						<span class="tm-cell-text">{{ item.provider }}</span>
						<span class="tm-cell-text">{{ item.bookingReference }}</span>
						<span class="tm-cell-meta">{{ bookingSpan(item) }}</span>
						<span class="tm-cell-meta">{{ formatTimestamp(item.createdAt) }}</span>
						<span class="tm-badges tm-cell-status">
							<!-- Provider-side status only when it isn't the plain 'active'
							     case; the two axes are orthogonal, so both can show. -->
							<span v-if="item.status !== 'active'" class="tm-badge tm-badge-warning">
								{{ bookingStatusLabel(item.status) }}
							</span>
							<span class="tm-badge">{{ reviewStateLabel(item.reviewState) }}</span>
						</span>
					</summary>
					<div class="tm-row-body">
						<BookingDetails :booking="item" />

						<div class="tm-actions">
							<!-- Primary while the booking has no trip — filing it is then the
							     one thing still outstanding; secondary once it has one, where
							     the button is a way back in rather than a task. -->
							<NcButton v-if="item.reviewState === 'confirmed'"
								:variant="item.tripId === null ? 'primary' : 'secondary'"
								@click="openTripPicker(item)">
								{{ t('travelmanager', 'Trip') }}
							</NcButton>
							<NcButton v-for="target in reviewActions(item)"
								:key="target"
								:variant="reviewVariant(target)"
								@click="onReviewAction(item, target)">
								{{ actionLabel(item, target) }}
							</NcButton>
							<NcButton v-if="item.reviewState === 'discarded' || item.reviewState === 'archived'"
								variant="error"
								@click="askDelete(item)">
								{{ t('travelmanager', 'Delete permanently') }}
							</NcButton>
						</div>
					</div>
				</details>
			</li>
		</ol>

		<TripPickerDialog v-model:open="pickerOpen" :booking="pickerTarget" />

		<NcDialog v-model:open="deleteOpen"
			:name="t('travelmanager', 'Delete booking permanently?')"
			size="small">
			{{ t('travelmanager', 'This removes the booking for good. Because no trace is kept, a later email about the same booking will bring it back as a new draft — discarding instead keeps it out of your way permanently. This cannot be undone.') }}
			<template #actions>
				<NcButton variant="tertiary" @click="deleteOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDelete">
					{{ t('travelmanager', 'Delete permanently') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<style module>
/* Title takes the lion's share; Trip and Provider stretch too, since trip and
   supplier names vary far more in length than a type, a reference or a date.
   Fixed widths elsewhere, not auto: every row is its own grid container, so
   content-sized columns would land at a different x on each row. */
.columns {
	grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 110px minmax(0, 2fr) 140px 190px 165px 135px;
}

/* Columns drop as space runs out, least-scanned first. The heading row and the
   data rows have their cells in the same order, so one set of nth-child rules
   hides a column in both — that is why the heading's chevron cell is a real
   (empty) element rather than an offset. */
@media (max-width: 1100px) {
	.columns {
		grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 110px minmax(0, 2fr) 190px 135px;
	}

	/* Reference, Added — both are lookups rather than things you scan. */
	.columns > *:nth-child(6),
	.columns > *:nth-child(8) {
		display: none;
	}
}

@media (max-width: 800px) {
	.columns {
		grid-template-columns: 16px minmax(0, 1fr) 190px 135px;
	}

	/* Trip, Type, Provider — the title usually names the latter two anyway
	   ("AMS → SOU"), and the trip is one click away in the Trips view. */
	.columns > *:nth-child(3),
	.columns > *:nth-child(4),
	.columns > *:nth-child(5) {
		display: none;
	}
}
</style>
