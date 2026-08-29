<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t } from '@nextcloud/l10n'
import {
	type BookingSort,
	BOOKING_COLUMNS,
	bookingSpan,
	filterBookings,
	sortBookings,
} from './bookings'
import { type SortDirection, formatTimestamp, nextSortDirection, sortMarker } from './grid'
import { bookingStatusLabel, reviewStateLabel, typeName } from './labels'
import { isOpen, openDetail } from './navigation'
import { availableTypes, bookings, loading, tripColors, tripNames } from './store'

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

const tripColorFor = (tripId: number | null): string | null =>
	tripId === null ? null : (tripColors.value[tripId] ?? null)

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

		<!-- A grid, not a <table>: the headings are plain buttons, since table ARIA
		     would promise a structure the markup lacks. Rows do not expand — a
		     booking is shown and acted on in the detail panel, which is strictly
		     richer than the row body used to be and is the only place carrying the
		     trail back to the source email. -->
		<div v-if="filtered.length > 0" :class="['tm-row-summary', 'tm-grid-header', $style.columns]">
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
				<!-- The click handler on the row is a mouse convenience; the title
				     button is the real control, so the row stays keyboard- and
				     screen-reader-operable without claiming to be a widget it is not. -->
				<div :class="['tm-row', 'tm-row-summary', $style.columns, {
						'tm-muted': item.reviewState === 'discarded' || item.reviewState === 'archived',
						'tm-row-selected': isOpen('booking', item.id),
					}]"
					@click="openDetail('booking', item.id)">
					<button type="button"
						class="tm-cell-text tm-open-link"
						@click.stop.prevent="openDetail('booking', item.id)">
						{{ item.title || typeName(item.type) }}
					</button>
					<!-- The trip's colour, exactly as on the Trips grid, so the same trip
					     is recognisable from either list. The swatch is present but
					     invisible when that trip has no colour, so names line up down
					     the column; a booking with no trip at all has nothing to align
					     with and gets an empty cell. -->
					<span v-if="item.tripId !== null" class="tm-cell-name">
						<span class="tm-swatch"
							:class="{ 'tm-swatch-none': !tripColorFor(item.tripId) }"
							:style="{ backgroundColor: tripColorFor(item.tripId) ?? undefined }" />
						<span class="tm-cell-text">{{ tripNameFor(item.tripId) }}</span>
					</span>
					<span v-else class="tm-cell-text" />
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
				</div>
			</li>
		</ol>
	</div>
</template>

<style module>
/* Title takes the lion's share; Trip and Provider stretch too, since trip and
   supplier names vary far more in length than a type, a reference or a date.
   Fixed widths elsewhere, not auto: every row is its own grid container, so
   content-sized columns would land at a different x on each row. */
.columns {
	grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) 110px minmax(0, 2fr) 140px 190px 165px 135px;
}

/* Columns drop as space runs out, least-scanned first. The heading row and the
   data rows have their cells in the same order, so one set of nth-child rules
   hides a column in both. */
@media (max-width: 1100px) {
	.columns {
		grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) 110px minmax(0, 2fr) 190px 135px;
	}

	/* Reference, Added — both are lookups rather than things you scan. */
	.columns > *:nth-child(5),
	.columns > *:nth-child(7) {
		display: none;
	}
}

@media (max-width: 800px) {
	.columns {
		grid-template-columns: minmax(0, 1fr) 190px 135px;
	}

	/* Trip, Type, Provider — the title usually names the latter two anyway
	   ("AMS → SOU"), and the trip is one click away in the Trips view. */
	.columns > *:nth-child(2),
	.columns > *:nth-child(3),
	.columns > *:nth-child(4) {
		display: none;
	}
}
</style>
