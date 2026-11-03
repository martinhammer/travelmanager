<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t } from '@nextcloud/l10n'
import { openTripEditor } from './dialogs'
import GridSortMenu from './GridSortMenu.vue'
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
	<div class="tm-content tm-grid-view">
		<div class="tm-toolbar">
			<h2 class="tm-toolbar-heading">
				{{ t('travelmanager', 'Trips') }}
			</h2>
			<GridSortMenu :columns="columns"
				:sort="sort"
				:direction="direction"
				@select="onSortColumn($event as TripSort)" />
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
					     trip nobody has classified, rather than a placeholder lozenge.
					     A word only: the booking-type lozenges two columns over are words,
					     and one lozenge in three carrying a glyph reads as an
					     inconsistency rather than as a distinction. The calendar's own
					     bars are where the trip-type glyph lives. -->
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

/* Steps follow the space the grid has, not the window — see the note in
   BookingsView. Exclusive ranges, doubled class, same reasons. */
@container tm-grid (600px <= width < 950px) {
	/* The booking-lozenge strip is the widest thing here and the least urgent;
	   the bookings themselves are one click away in the detail panel. */
	.columns.columns {
		grid-template-columns: minmax(0, 1fr) 110px 190px;
	}

	.columns.columns > *:nth-child(4) {
		display: none;
	}
}

/* Compact: a two-line card — name and type, then dates and the booking count.
   Only the count survives of the lozenge strip; the per-type lozenges would
   overflow a phone, and the bookings are one tap away in the panel.
   600px: keep in step with the compact block in grid.css. */
@container tm-grid (width < 600px) {
	.columns.columns {
		grid-template-columns: minmax(0, 1fr) auto;
		grid-template-areas:
			'name   type'
			'travel bookings';
	}

	.columns.columns > *:nth-child(1) { grid-area: name; }
	.columns.columns > *:nth-child(3) { grid-area: travel; }

	/* Top, not centred: beside a name that wraps, a centred badge lands on
	   its second line and reads as belonging to the dates below. */
	.columns.columns > *:nth-child(2) {
		grid-area: type;
		justify-self: end;
		align-self: start;
	}

	.columns.columns > *:nth-child(4) {
		grid-area: bookings;
		justify-self: end;
	}

	/* The name is the row's identity, so on a phone it wraps instead of
	   truncating: a card has the height to spare, and a trip list of
	   "UAE Nov…" ellipses cannot be told apart. The dot sits on the first line
	   rather than centring on a two-line name.
	   :global, because these are grid.css classes and a module would otherwise
	   hash them into names nothing carries. */
	.columns.columns > *:nth-child(1) {
		align-items: flex-start;
	}

	.columns.columns > *:nth-child(1) :global(.tm-swatch) {
		margin-block-start: calc((1lh - 14px) / 2);
	}

	.columns.columns > *:nth-child(1) :global(.tm-cell-text) {
		min-width: 0;
		overflow: visible;
		white-space: normal;
		overflow-wrap: anywhere;
		text-overflow: clip;
	}

	/* Dates may break at the arrow rather than run under the count badge. */
	.columns.columns > *:nth-child(3) {
		min-width: 0;
		white-space: normal;
	}

	/* Only the count: the per-type lozenges are the strip the full layout has a
	   340px column for. */
	.columns.columns > *:nth-child(4) :global(.tm-badge) + :global(.tm-badge) {
		display: none;
	}
}
</style>
