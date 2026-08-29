<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t } from '@nextcloud/l10n'
import { openTripEditor } from './dialogs'
import { type SortDirection, formatSpan, nextSortDirection, sortMarker } from './grid'
import { tripLabel, tripTypeLabel, typeName } from './labels'
import { isOpen, openDetail } from './navigation'
import { allTripRows, loading, trips } from './store'
import { type TripSort, TRIP_COLUMNS, filterTripsByPeriod, sortTrips } from './trips'

const filter = ref('all')
const sort = ref<TripSort>('travel')
const direction = ref<SortDirection>('asc')

const visible = computed(() => sortTrips(
	filterTripsByPeriod(allTripRows.value, filter.value),
	sort.value,
	direction.value,
))

const columnLabels: Record<TripSort, string> = {
	name: t('travelmanager', 'Trip'),
	type: t('travelmanager', 'Type'),
	travel: t('travelmanager', 'Travel dates'),
	bookings: t('travelmanager', 'Bookings'),
}

const columns = TRIP_COLUMNS.map((column) => ({ key: column.key, label: columnLabels[column.key] }))

const onSortColumn = (column: TripSort): void => {
	direction.value = nextSortDirection(TRIP_COLUMNS, column, sort.value, direction.value)
	sort.value = column
}

const filters: { key: string, label: string }[] = [
	{ key: 'all', label: t('travelmanager', 'All') },
	{ key: 'current', label: t('travelmanager', 'Current') },
	{ key: 'future', label: t('travelmanager', 'Future') },
	{ key: 'past', label: t('travelmanager', 'Past') },
]

// Creating a trip is the one dialog raised from here rather than from a trip's
// detail panel — there is no trip yet to open one for.
const onNewTrip = () => openTripEditor(null)
</script>

<template>
	<div class="tm-content">
		<div class="tm-toolbar">
			<h2 class="tm-toolbar-heading">
				{{ t('travelmanager', 'Trips') }}
			</h2>
			<NcButton variant="primary" @click="onNewTrip">
				{{ t('travelmanager', 'Create trip') }}
			</NcButton>
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
			:name="trips.length === 0 ? t('travelmanager', 'No trips yet') : t('travelmanager', 'Nothing matches this filter')"
			:description="trips.length === 0
				? t('travelmanager', 'Create a trip, then group your bookings under it.')
				: t('travelmanager', 'Try a different filter to see your other trips.')" />

		<div v-if="visible.length > 0" :class="['tm-row-summary', 'tm-grid-header', $style.columns]">
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
		<ol v-if="visible.length > 0" class="tm-rows">
			<li v-for="row in visible" :key="row.trip.id">
				<!-- The click handler on the row is a mouse convenience; the name
				     button is the real control, so the row stays keyboard- and
				     screen-reader-operable without claiming to be a widget it is not. -->
				<div :class="['tm-row', 'tm-row-summary', $style.columns, {
						'tm-row-selected': isOpen('trip', row.trip.id),
					}]"
					@click="openDetail('trip', row.trip.id)">
					<!-- The colour rides with the name because it identifies the trip;
					     the type says what it is for, so it sits with the other
					     lozenges. -->
					<span class="tm-cell-name">
						<span class="tm-swatch"
							:class="{ 'tm-swatch-none': !row.trip.color }"
							:style="row.trip.color ? { backgroundColor: row.trip.color } : {}" />
						<button type="button"
							class="tm-cell-text tm-open-link"
							@click.stop.prevent="openDetail('trip', row.trip.id)">
							{{ tripLabel(row.trip) }}
						</button>
					</span>
					<!-- What the trip is *for*, in its own sortable column. Empty for a
					     trip nobody has classified, rather than a placeholder lozenge. -->
					<span class="tm-badges tm-cell-status">
						<span v-if="tripTypeLabel(row.trip.type)" class="tm-badge">
							{{ tripTypeLabel(row.trip.type) }}
						</span>
					</span>
					<span class="tm-cell-meta">{{ formatSpan(row.start, row.end) }}</span>
					<!-- Count plus one lozenge per distinct *booking* type: what the trip
					     is made of, without opening it. -->
					<span class="tm-badges tm-cell-status">
						<span class="tm-badge">
							{{ t('travelmanager', '{n} booking(s)', { n: row.bookings.length }) }}
						</span>
						<span v-for="type in row.types" :key="type" class="tm-badge">
							{{ typeName(type) }}
						</span>
					</span>
				</div>
			</li>
		</ol>
	</div>
</template>

<style module>
/* Name | Type | Travel dates | booking lozenges. The name takes the stretch;
   Type needs only its one word, and the lozenge strip a generous fixed width
   since its count varies with the trip's booking types. */
.columns {
	grid-template-columns: minmax(0, 1fr) 110px 190px 340px;
}

@media (max-width: 900px) {
	/* The booking-lozenge strip is the widest thing here and the least urgent;
	   the bookings themselves are one click away in the detail panel. */
	.columns {
		grid-template-columns: minmax(0, 1fr) 110px 190px;
	}

	.columns > *:nth-child(4) {
		display: none;
	}
}

@media (max-width: 680px) {
	/* Then Type: it is the narrowest, but the name and the dates are what you
	   scan a trip list for. */
	.columns {
		grid-template-columns: minmax(0, 1fr) 190px;
	}

	.columns > *:nth-child(2) {
		display: none;
	}
}
</style>
