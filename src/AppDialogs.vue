<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
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
import { bookingLabel, bookingMeta, tripLabel, tripTypeLabel } from './labels'
import type { TripType } from './api'
import { TRIP_TYPES } from './trips'
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
// '' rather than null for both: NcRadioGroup and NcColorPicker model strings,
// and '' is also what the API reads as "clear it", so nothing has to translate
// between the form's empty and the wire's empty.
const editType = ref<TripType | ''>('')
const editColor = ref('')

watch(tripEditorOpen, (open) => {
	if (open) {
		const target = tripEditorTarget.value
		editName.value = target === null ? '' : decodeHtmlEntities(target.name)
		editType.value = target?.type ?? ''
		editColor.value = target?.color ?? ''
	}
})

const typeOptions = TRIP_TYPES.map((type) => ({ type, label: tripTypeLabel(type) }))

const submitTrip = async () => {
	const name = editName.value.trim()
	if (!name) {
		return
	}
	try {
		// Sent on every save, including empty, so clearing a type or colour is a
		// change like any other rather than something the form cannot express.
		const fields = { type: editType.value, color: editColor.value }
		if (tripEditorTarget.value !== null) {
			await updateTrip(tripEditorTarget.value.id, { name, ...fields })
		} else {
			await createTrip(name, fields)
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

			<!-- Plain radios in a row rather than Nextcloud's button variant: with
			     nothing chosen yet — which is every unclassified trip — a pair of
			     segmented buttons reads as two actions, giving no sign that one of
			     them is meant to be picked. The circles say so even when empty.
			     Same shape as the trip picker's list, one line instead of many. -->
			<fieldset class="tm-form-row">
				<legend class="tm-form-label">
					{{ t('travelmanager', 'Type') }}
				</legend>
				<div class="tm-form-controls">
					<NcCheckboxRadioSwitch v-for="option in typeOptions"
						:key="option.type"
						v-model="editType"
						type="radio"
						name="trip-type"
						:value="option.type">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</fieldset>

			<div class="tm-form-row">
				<span class="tm-form-label">{{ t('travelmanager', 'Colour') }}</span>
				<div class="tm-form-controls">
					<!-- An NcButton as the trigger, not a bare one: Nextcloud's core
					     stylesheet claims every bare button (see the note in
					     CalendarBar.vue), and .button-vue is exactly what it excludes.
					     The swatch is then a plain span and needs no fighting. -->
					<!-- Not v-model: the picker models `string | undefined`, and '' is
					     neither a colour nor "none" to it. Mapped at the boundary so the
					     form can keep using '' throughout, which is also what the API
					     reads as "clear it". Both events are handled — the palette emits
					     update:modelValue, the Choose button emits submit. -->
					<NcColorPicker :model-value="editColor || undefined"
						@update:model-value="editColor = $event ?? ''"
						@submit="editColor = $event ?? ''">
						<NcButton variant="secondary">
							<template #icon>
								<span class="tm-swatch"
									:class="{ 'tm-swatch-empty': !editColor }"
									:style="editColor ? { backgroundColor: editColor } : {}" />
							</template>
							{{ editColor || t('travelmanager', 'Choose a colour') }}
						</NcButton>
					</NcColorPicker>
					<NcButton v-if="editColor" variant="tertiary" @click="editColor = ''">
						{{ t('travelmanager', 'Clear') }}
					</NcButton>
				</div>
			</div>

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
