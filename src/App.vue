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
	type BookingWithSegments,
	type Trip,
	confirmBooking,
	createTrip,
	discardBooking,
	listBookings,
	listTrips,
} from './api'
import { draftCount as countDrafts, filterByStatus } from './bookings'

const bookings = ref<BookingWithSegments[]>([])
const trips = ref<Trip[]>([])
const filter = ref<string>('draft')
const loading = ref(true)
const newTripOpen = ref(false)
const newTripName = ref('')

const filtered = computed(() => filterByStatus(bookings.value, filter.value))

const draftCount = computed(() => countDrafts(bookings.value))

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
	newTripName.value = ''
	newTripOpen.value = true
}

const submitNewTrip = async () => {
	const name = newTripName.value.trim()
	if (!name) {
		return
	}
	try {
		await createTrip(name)
		newTripOpen.value = false
		newTripName.value = ''
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not create trip'))
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
				<NcAppNavigationItem :name="t('travelmanager', 'New trip')"
					@click="onNewTrip" />
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div :class="$style.content">
				<NcEmptyContent v-if="!loading && filtered.length === 0"
					:name="t('travelmanager', 'Nothing here yet')"
					:description="t('travelmanager', 'Travel bookings extracted from your mailbox will appear here as drafts.')" />
				<div v-for="item in filtered" :key="item.booking.id" :class="$style.card">
					<div :class="$style.cardHeader">
						<strong>{{ item.booking.title || item.booking.type }}</strong>
						<span :class="$style.badge">{{ item.booking.status }}</span>
					</div>
					<div :class="$style.meta">
						<span>{{ item.booking.type }}</span>
						<span v-if="item.booking.provider">· {{ item.booking.provider }}</span>
						<span v-if="item.booking.bookingReference">· {{ item.booking.bookingReference }}</span>
					</div>
					<ul :class="$style.segments">
						<li v-for="seg in item.segments" :key="seg.id">
							<span v-if="seg.flightNumber">{{ seg.flightNumber }} </span>
							<span v-if="seg.origin">{{ seg.origin }}</span>
							<span v-if="seg.destination"> → {{ seg.destination }}</span>
							<span v-if="seg.location">{{ seg.location }}</span>
							<span :class="$style.time">{{ seg.startLocal }}<span v-if="seg.startTimezone"> ({{ seg.startTimezone }})</span></span>
						</li>
					</ul>
					<div v-if="item.booking.status === 'draft'" :class="$style.actions">
						<NcButton variant="primary" @click="onConfirm(item.booking.id)">
							{{ t('travelmanager', 'Confirm') }}
						</NcButton>
						<NcButton variant="tertiary" @click="onDiscard(item.booking.id)">
							{{ t('travelmanager', 'Discard') }}
						</NcButton>
					</div>
				</div>
			</div>
		</NcAppContent>

		<NcDialog v-model:open="newTripOpen"
			:name="t('travelmanager', 'New trip')"
			size="small">
			<NcTextField v-model:value="newTripName"
				:label="t('travelmanager', 'Trip name')"
				@keydown.enter="submitNewTrip" />
			<template #actions>
				<NcButton variant="tertiary" @click="newTripOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!newTripName.trim()"
					@click="submitNewTrip">
					{{ t('travelmanager', 'Create') }}
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
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 4px 0;
}

.segments {
	margin: 8px 0;
}

.segments li {
	padding: 2px 0;
}

.time {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
}

.actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>
