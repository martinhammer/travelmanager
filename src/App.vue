<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import {
	type Booking,
	type Trip,
	assignBookingToTrip,
	confirmBooking,
	createTrip,
	deleteTrip,
	discardBooking,
	listBookings,
	listTrips,
	updateTrip,
} from './api'
import {
	bookingHeaderFields,
	bookingsForTrip,
	carFields,
	decodeHtmlEntities,
	draftCount as countDrafts,
	filterByStatus,
	flightSegmentFields,
	hotelFields,
	linkDialogBookings,
	passengerLines,
} from './bookings'

const bookings = ref<Booking[]>([])
const trips = ref<Trip[]>([])
const filter = ref<string>('draft')
const loading = ref(true)
const newTripOpen = ref(false)
const newTripName = ref('')
// When set, the trip dialog edits this trip instead of creating a new one.
const editTripTarget = ref<Trip | null>(null)

// Trip-linking dialog state.
const linkOpen = ref(false)
const linkTarget = ref<Trip | null>(null)
// Trip-deletion confirmation state.
const deleteTripOpen = ref(false)
const deleteTripTarget = ref<Trip | null>(null)

const filtered = computed(() => filterByStatus(bookings.value, filter.value))

const draftCount = computed(() => countDrafts(bookings.value))

const linkCandidates = computed(() => linkTarget.value ? linkDialogBookings(bookings.value, linkTarget.value.id) : [])

const bookingLabel = (item: Booking): string => decodeHtmlEntities(item.title || item.type)

const tripLabel = (trip: Trip): string => decodeHtmlEntities(trip.name)

const reload = async () => {
	loading.value = true
	try {
		[bookings.value, trips.value] = await Promise.all([listBookings(), listTrips()])
	} catch (e) {
		showError(t('travelmanager', 'Could not load travel data'))
	} finally {
		loading.value = false
	}
}

const onConfirm = async (id: number) => {
	try {
		await confirmBooking(id)
		showSuccess(t('travelmanager', 'Booking confirmed'))
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not confirm booking'))
	}
}

const onDiscard = async (id: number) => {
	try {
		await discardBooking(id)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not discard booking'))
	}
}

const onNewTrip = () => {
	editTripTarget.value = null
	newTripName.value = ''
	newTripOpen.value = true
}

const onEditTrip = (trip: Trip) => {
	editTripTarget.value = trip
	// Decoded so a leftover entity (see decodeHtmlEntities) gets cleaned up on save.
	newTripName.value = decodeHtmlEntities(trip.name)
	newTripOpen.value = true
}

const submitNewTrip = async () => {
	const name = newTripName.value.trim()
	if (!name) {
		return
	}
	try {
		if (editTripTarget.value !== null) {
			await updateTrip(editTripTarget.value.id, { name })
		} else {
			await createTrip(name)
		}
		newTripOpen.value = false
		newTripName.value = ''
		editTripTarget.value = null
		await reload()
	} catch (e) {
		showError(editTripTarget.value !== null ? t('travelmanager', 'Could not update trip') : t('travelmanager', 'Could not create trip'))
	}
}

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

const askDeleteTrip = (trip: Trip) => {
	deleteTripTarget.value = trip
	deleteTripOpen.value = true
}

const confirmDeleteTrip = async () => {
	if (deleteTripTarget.value === null) {
		return
	}
	try {
		await deleteTrip(deleteTripTarget.value.id)
		showSuccess(t('travelmanager', 'Trip deleted'))
		deleteTripOpen.value = false
		deleteTripTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the trip'))
	}
}

onMounted(reload)
</script>

<template>
	<NcContent app-name="travelmanager">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :name="t('travelmanager', 'Drafts')"
					:active="filter === 'draft'"
					@click="filter = 'draft'">
					<template #counter>
						{{ draftCount }}
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Confirmed')"
					:active="filter === 'confirmed'"
					@click="filter = 'confirmed'" />
				<NcAppNavigationItem :name="t('travelmanager', 'All bookings')"
					:active="filter === 'all'"
					@click="filter = 'all'" />
				<NcAppNavigationItem :name="t('travelmanager', 'Trips')"
					:active="filter === 'trips'"
					@click="filter = 'trips'">
					<template #counter>
						{{ trips.length }}
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div v-if="filter === 'trips'" :class="$style.content">
				<div :class="$style.tripsToolbar">
					<h2 :class="$style.tripsHeading">
						{{ t('travelmanager', 'Trips') }}
					</h2>
					<NcButton variant="primary" @click="onNewTrip">
						{{ t('travelmanager', 'Create trip') }}
					</NcButton>
				</div>
				<NcEmptyContent v-if="!loading && trips.length === 0"
					:name="t('travelmanager', 'No trips yet')"
					:description="t('travelmanager', 'Create a trip, then group your bookings under it.')" />
				<details v-for="trip in trips" :key="trip.id" :class="$style.tripCard">
					<summary :class="$style.tripSummary">
						<strong>{{ tripLabel(trip) }}</strong>
						<span :class="$style.badge">
							{{ t('travelmanager', '{n} booking(s)', { n: bookingsForTrip(bookings, trip.id).length }) }}
						</span>
					</summary>
					<div :class="$style.tripBody">
						<ul v-if="bookingsForTrip(bookings, trip.id).length > 0" :class="$style.tripBookings">
							<li v-for="item in bookingsForTrip(bookings, trip.id)"
								:key="item.id"
								:class="$style.tripBooking">
								<div :class="$style.tripBookingInfo">
									<strong>{{ bookingLabel(item) }}</strong>
									<span :class="$style.tripBookingMeta">{{ item.type }} · {{ item.status }}</span>
								</div>
								<NcButton variant="tertiary" @click="onUnlink(item.id)">
									{{ t('travelmanager', 'Unlink') }}
								</NcButton>
							</li>
						</ul>
						<p v-else :class="$style.tripEmpty">
							{{ t('travelmanager', 'No bookings linked to this trip yet.') }}
						</p>
						<div :class="$style.actions">
							<NcButton variant="secondary" @click="openLink(trip)">
								{{ t('travelmanager', 'Link booking') }}
							</NcButton>
							<NcButton variant="secondary" @click="onEditTrip(trip)">
								{{ t('travelmanager', 'Edit trip') }}
							</NcButton>
							<NcButton variant="error" @click="askDeleteTrip(trip)">
								{{ t('travelmanager', 'Delete trip') }}
							</NcButton>
						</div>
					</div>
				</details>
			</div>
			<div v-else :class="$style.content">
				<NcEmptyContent v-if="!loading && filtered.length === 0"
					:name="t('travelmanager', 'Nothing here yet')"
					:description="t('travelmanager', 'Travel bookings extracted from your mailbox will appear here as drafts.')" />
				<div v-for="item in filtered" :key="item.id" :class="$style.card">
					<div :class="$style.cardHeader">
						<strong>{{ item.title || item.type }}</strong>
						<span :class="$style.badge">{{ item.status }}</span>
					</div>
					<div :class="[$style.fields, $style.meta]">
						<template v-for="field in bookingHeaderFields(item)" :key="field.label">
							<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
							<span :class="$style.fieldValue">{{ field.value }}</span>
						</template>
					</div>

					<!-- Flight: passengers + one row per leg -->
					<template v-if="item.type === 'flight'">
						<div v-if="passengerLines(item.details).length > 0" :class="$style.fields">
							<span :class="$style.fieldLabel">{{ t('travelmanager', 'Passengers') }}</span>
							<span :class="$style.fieldValue">
								<span v-for="(line, i) in passengerLines(item.details)" :key="i" :class="$style.passenger">
									{{ line }}
								</span>
							</span>
						</div>
						<ul :class="$style.segments">
							<li v-for="(seg, i) in (item.details.segments ?? [])" :key="i" :class="$style.segment">
								<span v-if="(item.details.segments ?? []).length > 1" :class="$style.segmentIndex">
									{{ t('travelmanager', 'Leg {n}', { n: i + 1 }) }}
								</span>
								<div :class="$style.fields">
									<template v-for="field in flightSegmentFields(seg)" :key="field.label">
										<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
										<span :class="$style.fieldValue">{{ field.value }}</span>
									</template>
								</div>
							</li>
						</ul>
					</template>

					<!-- Car rental / accommodation: a single labelled detail block -->
					<div v-else-if="item.type === 'car_rental'" :class="[$style.fields, $style.typeFields]">
						<template v-for="field in carFields(item.details)" :key="field.label">
							<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
							<span :class="$style.fieldValue">{{ field.value }}</span>
						</template>
					</div>
					<div v-else-if="item.type === 'accommodation'" :class="[$style.fields, $style.typeFields]">
						<template v-for="field in hotelFields(item.details)" :key="field.label">
							<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
							<span :class="$style.fieldValue">{{ field.value }}</span>
						</template>
					</div>

					<div v-if="item.status === 'draft'" :class="$style.actions">
						<NcButton variant="primary" @click="onConfirm(item.id)">
							{{ t('travelmanager', 'Confirm') }}
						</NcButton>
						<NcButton variant="tertiary" @click="onDiscard(item.id)">
							{{ t('travelmanager', 'Discard') }}
						</NcButton>
					</div>
				</div>
			</div>
		</NcAppContent>

		<NcDialog v-model:open="newTripOpen"
			:name="editTripTarget ? t('travelmanager', 'Edit trip') : t('travelmanager', 'New trip')"
			size="small">
			<NcTextField v-model="newTripName"
				:label="t('travelmanager', 'Trip name')"
				@keydown.enter="submitNewTrip" />
			<template #actions>
				<NcButton variant="tertiary" @click="newTripOpen = false; editTripTarget = null">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!newTripName.trim()"
					@click="submitNewTrip">
					{{ editTripTarget ? t('travelmanager', 'Save') : t('travelmanager', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="linkOpen"
			:name="t('travelmanager', 'Link a booking')"
			size="normal">
			<p v-if="linkTarget" :class="$style.tripEmpty">
				{{ t('travelmanager', 'Linking to') }} “<strong>{{ tripLabel(linkTarget) }}</strong>”.
			</p>
			<p v-if="linkCandidates.length === 0" :class="$style.tripEmpty">
				{{ t('travelmanager', 'No bookings to show here.') }}
			</p>
			<ul v-else :class="$style.tripBookings">
				<li v-for="item in linkCandidates" :key="item.id" :class="$style.tripBooking">
					<div :class="$style.tripBookingInfo">
						<strong>{{ bookingLabel(item) }}</strong>
						<span :class="$style.tripBookingMeta">{{ item.type }} · {{ item.status }}</span>
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

		<NcDialog v-model:open="deleteTripOpen"
			:name="t('travelmanager', 'Delete trip?')"
			size="small">
			{{ t('travelmanager', 'This deletes the trip. Its bookings are kept and simply unlinked, so you can re-group them later. This cannot be undone.') }}
			<template #actions>
				<NcButton variant="tertiary" @click="deleteTripOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDeleteTrip">
					{{ t('travelmanager', 'Delete trip') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcContent>
</template>

<style module>
.content {
	padding: 16px;
	max-width: 800px;
}

.card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	margin-bottom: 12px;
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.badge {
	font-size: 0.8em;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}

.meta {
	margin: 8px 0;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.typeFields {
	margin-top: 4px;
}

.passenger {
	display: block;
}

.segments {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.segment {
	padding: 8px 0;
	border-top: 1px solid var(--color-border);
}

.segment:first-child {
	border-top: none;
	padding-top: 4px;
}

.segmentIndex {
	display: block;
	font-size: 0.85em;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 1px 12px;
	align-items: baseline;
	margin: 0;
	line-height: 1.4;
}

.fieldLabel {
	color: var(--color-text-maxcontrast);
	text-align: start;
}

.fieldValue {
	min-width: 0;
	overflow-wrap: anywhere;
}

.actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.tripsToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.tripsHeading {
	margin: 0;
}

.tripCard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 12px;
}

.tripSummary {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	cursor: pointer;
}

.tripBody {
	padding: 0 16px 12px;
}

.tripBookings {
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}

.tripBooking {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-top: 1px solid var(--color-border);
}

.tripBookingInfo {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.tripBookingMeta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.tripEmpty {
	color: var(--color-text-maxcontrast);
	margin: 8px 0;
}
</style>
