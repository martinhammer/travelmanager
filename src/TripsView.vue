<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { Trip } from './api'
import { assignBookingToTrip, createTrip, deleteTrip, updateTrip } from './api'
import { decodeHtmlEntities, linkDialogBookings } from './bookings'
import { type SortDirection, formatSpan, nextSortDirection, sortMarker } from './grid'
import { bookingLabel, bookingMeta, tripLabel, typeName } from './labels'
import { isOpen, openDetail } from './navigation'
import { allTripRows, bookings, loading, reload, trips } from './store'
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

// --- create / edit --------------------------------------------------------

const editOpen = ref(false)
const editName = ref('')
// When set, the dialog edits this trip instead of creating a new one.
const editTarget = ref<Trip | null>(null)

const onNewTrip = () => {
	editTarget.value = null
	editName.value = ''
	editOpen.value = true
}

const onEditTrip = (trip: Trip) => {
	editTarget.value = trip
	// Decoded so a leftover entity (see decodeHtmlEntities) gets cleaned up on save.
	editName.value = decodeHtmlEntities(trip.name)
	editOpen.value = true
}

const submitTrip = async () => {
	const name = editName.value.trim()
	if (!name) {
		return
	}
	try {
		if (editTarget.value !== null) {
			await updateTrip(editTarget.value.id, { name })
		} else {
			await createTrip(name)
		}
		editOpen.value = false
		editName.value = ''
		editTarget.value = null
		await reload()
	} catch (e) {
		showError(editTarget.value !== null
			? t('travelmanager', 'Could not update trip')
			: t('travelmanager', 'Could not create trip'))
	}
}

// --- linking --------------------------------------------------------------

const linkOpen = ref(false)
const linkTarget = ref<Trip | null>(null)

const linkCandidates = computed(() =>
	linkTarget.value ? linkDialogBookings(bookings.value, linkTarget.value.id) : [])

const openLink = (trip: Trip) => {
	linkTarget.value = trip
	linkOpen.value = true
}

const onLink = async (bookingId: number) => {
	if (linkTarget.value === null) {
		return
	}
	try {
		await assignBookingToTrip(bookingId, linkTarget.value.id)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not link the booking'))
	}
}

const onUnlink = async (bookingId: number) => {
	try {
		await assignBookingToTrip(bookingId, null)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not unlink the booking'))
	}
}

// --- deletion -------------------------------------------------------------

const deleteOpen = ref(false)
const deleteTarget = ref<Trip | null>(null)

const askDelete = (trip: Trip) => {
	deleteTarget.value = trip
	deleteOpen.value = true
}

const confirmDelete = async () => {
	if (deleteTarget.value === null) {
		return
	}
	try {
		await deleteTrip(deleteTarget.value.id)
		showSuccess(t('travelmanager', 'Trip deleted'))
		deleteOpen.value = false
		deleteTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the trip'))
	}
}
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
		<ol v-if="visible.length > 0" class="tm-rows">
			<li v-for="row in visible" :key="row.trip.id">
				<details :class="['tm-row', { 'tm-row-selected': isOpen('trip', row.trip.id) }]">
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
							@click.stop.prevent="openDetail('trip', row.trip.id)">
							{{ tripLabel(row.trip) }}
						</button>
						<span class="tm-cell-meta">{{ formatSpan(row.start, row.end) }}</span>
						<!-- Count plus one lozenge per distinct type: what the trip is made
						     of, without opening it. -->
						<span class="tm-badges tm-cell-status">
							<span class="tm-badge">
								{{ t('travelmanager', '{n} booking(s)', { n: row.bookings.length }) }}
							</span>
							<span v-for="type in row.types" :key="type" class="tm-badge">
								{{ typeName(type) }}
							</span>
						</span>
					</summary>
					<div class="tm-row-body">
						<ul v-if="row.bookings.length > 0" class="tm-list">
							<!-- Read-only here: linking and unlinking both happen in the
							     Bookings dialog, so there is one place that changes what a
							     trip contains. -->
							<li v-for="item in row.bookings" :key="item.id" class="tm-list-item">
								<div class="tm-list-item-info">
									<strong>{{ bookingLabel(item) }}</strong>
									<span class="tm-meta">{{ bookingMeta(item) }}</span>
								</div>
							</li>
						</ul>
						<p v-else class="tm-empty">
							{{ t('travelmanager', 'No bookings linked to this trip yet.') }}
						</p>
						<div class="tm-actions">
							<NcButton variant="primary" @click="openLink(row.trip)">
								{{ t('travelmanager', 'Bookings') }}
							</NcButton>
							<NcButton variant="secondary" @click="onEditTrip(row.trip)">
								{{ t('travelmanager', 'Edit trip') }}
							</NcButton>
							<NcButton variant="error" @click="askDelete(row.trip)">
								{{ t('travelmanager', 'Delete trip') }}
							</NcButton>
						</div>
					</div>
				</details>
			</li>
		</ol>

		<NcDialog v-model:open="editOpen"
			:name="editTarget ? t('travelmanager', 'Edit trip') : t('travelmanager', 'New trip')"
			size="small">
			<NcTextField v-model="editName"
				:label="t('travelmanager', 'Trip name')"
				@keydown.enter="submitTrip" />
			<template #actions>
				<NcButton variant="tertiary" @click="editOpen = false; editTarget = null">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!editName.trim()" @click="submitTrip">
					{{ editTarget ? t('travelmanager', 'Save') : t('travelmanager', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="linkOpen"
			:name="t('travelmanager', 'Link a booking')"
			size="normal">
			<p v-if="linkTarget" class="tm-empty">
				{{ t('travelmanager', 'Linking to') }} “<strong>{{ tripLabel(linkTarget) }}</strong>”.
			</p>
			<p v-if="linkCandidates.length === 0" class="tm-empty">
				{{ t('travelmanager', 'No bookings to show here.') }}
			</p>
			<ul v-else class="tm-list">
				<li v-for="item in linkCandidates" :key="item.id" class="tm-list-item">
					<div class="tm-list-item-info">
						<strong>{{ bookingLabel(item) }}</strong>
						<span class="tm-meta">{{ bookingMeta(item) }}</span>
					</div>
					<NcButton v-if="item.tripId === linkTarget?.id" variant="tertiary" @click="onUnlink(item.id)">
						{{ t('travelmanager', 'Unlink') }}
					</NcButton>
					<NcButton v-else variant="secondary" @click="onLink(item.id)">
						{{ t('travelmanager', 'Link') }}
					</NcButton>
				</li>
			</ul>
			<template #actions>
				<NcButton variant="primary" @click="linkOpen = false">
					{{ t('travelmanager', 'Done') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="deleteOpen"
			:name="t('travelmanager', 'Delete trip?')"
			size="small">
			{{ t('travelmanager', 'This deletes the trip. Its bookings are kept and simply unlinked, so you can re-group them later. This cannot be undone.') }}
			<template #actions>
				<NcButton variant="tertiary" @click="deleteOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDelete">
					{{ t('travelmanager', 'Delete trip') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<style module>
/* Only three columns, so the name takes the stretch and the lozenges get a
   generous fixed strip — their number varies with the trip's booking types. */
.columns {
	grid-template-columns: 16px minmax(0, 1fr) 190px 340px;
}

@media (max-width: 800px) {
	/* The lozenge strip is the widest thing here and the least urgent; the
	   bookings themselves are one click away in the expanded row. */
	.columns {
		grid-template-columns: 16px minmax(0, 1fr) 190px;
	}

	.columns > *:nth-child(4) {
		display: none;
	}
}
</style>
