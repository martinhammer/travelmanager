<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { Booking } from './api'
import { assignBookingToTrip, createTrip, setBookingReviewState } from './api'
import { bookingSpan } from './bookings'
import { bookingLabel, reviewLabels, tripLabel, tripSummary } from './labels'
import { allTripRows, reload } from './store'
import { canCreateTrip, searchTrips, suggestedTrips } from './trips'

/**
 * Where a booking is filed. Reached two ways: confirming a draft (the moment it
 * becomes one you have decided to keep, so the natural point to group it), and
 * the Trip button on an already-confirmed booking.
 */
const props = defineProps<{ open: boolean, booking: Booking | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

// One selection across both sections: a trip id, 'none', or 'new' (create
// whatever is typed in the search box).
const choice = ref('')
const search = ref('')
const busy = ref(false)

// Whether the dialog is also confirming, or only re-filing an already-confirmed
// booking. Drives its wording and whether "No trip" is worth offering.
const isDraft = computed(() => props.booking?.reviewState === 'draft')
const hasTrip = computed(() => props.booking?.tripId != null)

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	// A booking that already has a trip opens with it selected — the dialog is
	// then showing you where it is, not asking you to start from nothing. A draft
	// still starts empty: nothing pre-selected, so a wrong guess cannot slip
	// through on a single click.
	choice.value = props.booking?.tripId == null ? '' : String(props.booking.tripId)
	search.value = ''
})

// Trips whose dates line up with the booking's own — almost always the one you
// want. Hidden while searching: once you type you are browsing deliberately, and
// a pinned suggestion above filtered results is just noise.
const suggestions = computed(() => {
	if (props.booking === null || search.value.trim() !== '') {
		return []
	}
	return suggestedTrips(allTripRows.value, props.booking)
})

// The full list, minus anything already shown as a suggestion.
const candidates = computed(() => {
	const suggested = new Set(suggestions.value.map((row) => row.trip.id))
	return searchTrips(allTripRows.value, search.value).filter((row) => !suggested.has(row.trip.id))
})

const canCreate = computed(() => canCreateTrip(allTripRows.value, search.value))

/**
 * Enter in the search box takes the create row when there is one, otherwise the
 * first match — so a name can be typed and committed without reaching for the
 * mouse.
 */
const onSearchEnter = () => {
	if (canCreate.value) {
		choice.value = 'new'
		return
	}
	const first = candidates.value[0]
	if (first !== undefined) {
		choice.value = String(first.trip.id)
	}
}

/**
 * Apply the dialog: create the trip if asked, link unless "No trip", and confirm
 * when the booking was still a draft. The link is applied *before* the review
 * change so a failure there leaves the booking a draft — recoverable by pressing
 * Confirm again — rather than confirmed but orphaned.
 */
const submit = async () => {
	const item = props.booking
	if (item === null || busy.value || choice.value === '') {
		return
	}
	const confirming = isDraft.value
	busy.value = true
	try {
		let tripId: number | null = null
		if (choice.value === 'new') {
			tripId = (await createTrip(search.value.trim())).id
		} else if (choice.value !== 'none') {
			tripId = Number(choice.value)
		}
		// One call covers linking, moving and unlinking; skipped when the choice
		// is the trip the booking is already on, which is the ordinary outcome of
		// opening the dialog just to look.
		const changed = tripId !== item.tripId
		if (changed) {
			await assignBookingToTrip(item.id, tripId)
		}
		if (confirming) {
			await setBookingReviewState(item.id, 'confirmed')
			showSuccess(reviewLabels.confirmed.done)
		} else if (changed) {
			showSuccess(tripId === null
				? t('travelmanager', 'Booking removed from the trip')
				: t('travelmanager', 'Booking added to the trip'))
		}
		emit('update:open', false)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not update the booking'))
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<NcDialog :open="open"
		:name="isDraft
			? t('travelmanager', 'Confirm booking')
			: (hasTrip ? t('travelmanager', 'Change trip') : t('travelmanager', 'Add to a trip'))"
		size="normal"
		@update:open="emit('update:open', $event)">
		<div v-if="booking" :class="$style.header">
			<strong>{{ bookingLabel(booking) }}</strong>
			<span v-if="bookingSpan(booking)" class="tm-meta">{{ bookingSpan(booking) }}</span>
		</div>

		<!-- Rendered only when it has something in it, so a booking with no
		     dates simply gets the plain searchable list. -->
		<template v-if="suggestions.length > 0">
			<h3 :class="$style.heading">
				{{ t('travelmanager', 'Suggested') }}
			</h3>
			<div :class="$style.choices">
				<NcCheckboxRadioSwitch v-for="row in suggestions"
					:key="row.trip.id"
					v-model="choice"
					type="radio"
					name="trip-picker"
					:value="String(row.trip.id)">
					{{ tripLabel(row.trip) }}
					<span class="tm-meta"> — {{ tripSummary(row.trip.id) }}</span>
				</NcCheckboxRadioSwitch>
			</div>
		</template>

		<h3 :class="$style.heading">
			{{ t('travelmanager', 'All trips') }}
		</h3>
		<NcTextField v-model="search"
			:label="t('travelmanager', 'Search, or type a new trip name')"
			:disabled="busy"
			@keydown.enter="onSearchEnter" />
		<!-- One radio group across both sections (same `name`), so choosing in
		     one clears the other. -->
		<div :class="[$style.choices, $style.scroll]">
			<NcCheckboxRadioSwitch v-if="canCreate"
				v-model="choice"
				type="radio"
				name="trip-picker"
				value="new">
				{{ t('travelmanager', 'Create “{name}”', { name: search.trim() }) }}
			</NcCheckboxRadioSwitch>
			<!-- Offered when it can actually do something: confirming without a
			     trip, or unlinking one that has one. Omitted for a booking that
			     already has no trip, where it would be a control that does nothing. -->
			<NcCheckboxRadioSwitch v-if="isDraft || hasTrip"
				v-model="choice"
				type="radio"
				name="trip-picker"
				value="none">
				{{ t('travelmanager', 'No trip') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-for="row in candidates"
				:key="row.trip.id"
				v-model="choice"
				type="radio"
				name="trip-picker"
				:value="String(row.trip.id)">
				{{ tripLabel(row.trip) }}
				<span class="tm-meta"> — {{ tripSummary(row.trip.id) }}</span>
			</NcCheckboxRadioSwitch>
			<p v-if="candidates.length === 0 && !canCreate" class="tm-empty">
				{{ allTripRows.length === 0
					? t('travelmanager', 'No trips yet — type a name above to create your first.')
					: t('travelmanager', 'No trip matches that search.') }}
			</p>
		</div>
		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="emit('update:open', false)">
				{{ t('travelmanager', 'Cancel') }}
			</NcButton>
			<!-- Disabled until a choice is made: nothing is pre-selected, so an
			     enabled button would have no defined meaning. -->
			<NcButton variant="primary"
				:disabled="busy || choice === ''"
				@click="submit">
				{{ isDraft
					? t('travelmanager', 'Confirm')
					: (hasTrip ? t('travelmanager', 'Save') : t('travelmanager', 'Add to trip')) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.header {
	display: flex;
	flex-direction: column;
	margin-bottom: 12px;
}

.heading {
	font-size: 1em;
	color: var(--color-text-maxcontrast);
	margin: 16px 0 6px;
}

.choices {
	display: flex;
	flex-direction: column;
}

/* Capped so a long trip list scrolls inside the dialog rather than pushing the
   actions off the bottom of the screen. */
.scroll {
	max-height: 240px;
	overflow-y: auto;
	margin-top: 6px;
}
</style>
