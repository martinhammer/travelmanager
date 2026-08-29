import { ref } from 'vue'
import type { Booking, Trip } from './api'

/**
 * Which dialog is open, and on what.
 *
 * Module-level refs like `store.ts`, for the same reason: the dialogs are raised
 * from the detail panel *and* from a view toolbar, so no single component owns
 * them. Threading them through App.vue would put the shell in the middle of
 * every action it has nothing to do with.
 *
 * This module holds only the state and the "open it" calls — the dialogs
 * themselves, and what their confirm buttons do, live in `AppDialogs.vue`.
 */

/** Confirming a draft, or re-filing a confirmed booking. */
export const tripPickerOpen = ref(false)
export const tripPickerTarget = ref<Booking | null>(null)

/** Permanent deletion of a discarded/archived booking. */
export const deleteBookingOpen = ref(false)
export const deleteBookingTarget = ref<Booking | null>(null)

/** New trip when the target is null, rename when it is set. */
export const tripEditorOpen = ref(false)
export const tripEditorTarget = ref<Trip | null>(null)

/** Link/unlink bookings on one trip. */
export const linkOpen = ref(false)
export const linkTarget = ref<Trip | null>(null)

export const deleteTripOpen = ref(false)
export const deleteTripTarget = ref<Trip | null>(null)

/**
 * @param booking the booking to file
 */
export const openTripPicker = (booking: Booking): void => {
	tripPickerTarget.value = booking
	tripPickerOpen.value = true
}

/**
 * @param booking the booking to delete for good
 */
export const askDeleteBooking = (booking: Booking): void => {
	deleteBookingTarget.value = booking
	deleteBookingOpen.value = true
}

/**
 * @param trip the trip to rename, or null to create one
 */
export const openTripEditor = (trip: Trip | null): void => {
	tripEditorTarget.value = trip
	tripEditorOpen.value = true
}

/**
 * @param trip the trip whose bookings are being changed
 */
export const openLinkDialog = (trip: Trip): void => {
	linkTarget.value = trip
	linkOpen.value = true
}

/**
 * @param trip the trip to delete
 */
export const askDeleteTrip = (trip: Trip): void => {
	deleteTripTarget.value = trip
	deleteTripOpen.value = true
}
