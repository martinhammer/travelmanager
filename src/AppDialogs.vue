<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { assignBookingToTrip, createTrip, deleteBooking, deleteTrip, updateTrip } from './api'
import TripPickerDialog from './TripPickerDialog.vue'
import { decodeHtmlEntities, linkDialogBookings } from './bookings'
import {
	deleteBookingOpen,
	deleteBookingTarget,
	deleteTripOpen,
	deleteTripTarget,
	linkOpen,
	linkTarget,
	tripEditorOpen,
	tripEditorTarget,
	tripPickerOpen,
	tripPickerTarget,
} from './dialogs'
import { bookingLabel, bookingMeta, tripLabel } from './labels'
import { closeDetail, isOpen } from './navigation'
import { bookings, reload } from './store'

/**
 * Every dialog in the app, rendered once by the shell.
 *
 * They live here rather than in a view because the detail panel raises most of
 * them and the Trips toolbar raises one, so no view owns them any more. What is
 * open lives in `dialogs.ts`; this component is the markup plus what the confirm
 * buttons do.
 */

// --- booking: permanent deletion ------------------------------------------

const confirmDeleteBooking = async () => {
	const target = deleteBookingTarget.value
	if (target === null) {
		return
	}
	try {
		await deleteBooking(target.id)
		showSuccess(t('travelmanager', 'Booking deleted'))
		deleteBookingOpen.value = false
		// The panel would otherwise sit there showing "Not found".
		if (isOpen('booking', target.id)) {
			closeDetail()
		}
		deleteBookingTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the booking'))
	}
}

// --- trip: create / rename -------------------------------------------------

const editName = ref('')

// Seeded when the dialog opens, not on every target change, so typing is never
// overwritten. Decoded so a leftover entity (see decodeHtmlEntities) is cleaned
// up on save.
watch(tripEditorOpen, (open) => {
	if (open) {
		editName.value = tripEditorTarget.value === null ? '' : decodeHtmlEntities(tripEditorTarget.value.name)
	}
})

const submitTrip = async () => {
	const name = editName.value.trim()
	if (!name) {
		return
	}
	try {
		if (tripEditorTarget.value !== null) {
			await updateTrip(tripEditorTarget.value.id, { name })
		} else {
			await createTrip(name)
		}
		tripEditorOpen.value = false
		tripEditorTarget.value = null
		await reload()
	} catch (e) {
		showError(tripEditorTarget.value !== null
			? t('travelmanager', 'Could not update trip')
			: t('travelmanager', 'Could not create trip'))
	}
}

// --- trip: linking ---------------------------------------------------------

const linkCandidates = computed(() =>
	linkTarget.value ? linkDialogBookings(bookings.value, linkTarget.value.id) : [])

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

// --- trip: deletion --------------------------------------------------------

const confirmDeleteTrip = async () => {
	const target = deleteTripTarget.value
	if (target === null) {
		return
	}
	try {
		await deleteTrip(target.id)
		showSuccess(t('travelmanager', 'Trip deleted'))
		deleteTripOpen.value = false
		if (isOpen('trip', target.id)) {
			closeDetail()
		}
		deleteTripTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the trip'))
	}
}
</script>

<template>
	<div>
		<TripPickerDialog v-model:open="tripPickerOpen" :booking="tripPickerTarget" />

		<NcDialog v-model:open="deleteBookingOpen"
			:name="t('travelmanager', 'Delete booking permanently?')"
			size="small">
			{{ t('travelmanager', 'This removes the booking for good. Because no trace is kept, a later email about the same booking will bring it back as a new draft — discarding instead keeps it out of your way permanently. This cannot be undone.') }}
			<template #actions>
				<NcButton variant="tertiary" @click="deleteBookingOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDeleteBooking">
					{{ t('travelmanager', 'Delete permanently') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="tripEditorOpen"
			:name="tripEditorTarget ? t('travelmanager', 'Edit trip') : t('travelmanager', 'New trip')"
			size="small">
			<NcTextField v-model="editName"
				:label="t('travelmanager', 'Trip name')"
				@keydown.enter="submitTrip" />
			<template #actions>
				<NcButton variant="tertiary" @click="tripEditorOpen = false; tripEditorTarget = null">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!editName.trim()" @click="submitTrip">
					{{ tripEditorTarget ? t('travelmanager', 'Save') : t('travelmanager', 'Create') }}
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
	</div>
</template>
