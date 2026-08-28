import { computed, ref } from 'vue'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { Booking, Message, Trip } from './api'
import { listBookings, listMessages, listTrips } from './api'
import { bookingTypes, decodeHtmlEntities, draftCount } from './bookings'
import { needsAttention } from './messages'
import { tripRows } from './trips'

/**
 * The app's shared data: what has been loaded, and how to reload it.
 *
 * Module-level refs rather than provide/inject or prop threading. Every view,
 * the detail sidebar and most of the dialogs need the same three collections and
 * the same `reload()`, and passing them down would be a lot of ceremony for a
 * single-instance app. This mirrors how bookings.ts / trips.ts already work:
 * plain modules, no framework machinery.
 *
 * Presentation lives elsewhere — `labels.ts` for wording, `navigation.ts` for
 * what is open. This file is only about the data.
 */

export const bookings = ref<Booking[]>([])
export const trips = ref<Trip[]>([])
export const messages = ref<Message[]>([])
export const loading = ref(true)

/** Everything, in one round trip. Views re-read the refs; nothing else to do. */
export const reload = async (): Promise<void> => {
	loading.value = true
	try {
		[bookings.value, trips.value, messages.value] = await Promise.all([
			listBookings(),
			listTrips(),
			listMessages(),
		])
	} catch (e) {
		showError(t('travelmanager', 'Could not load travel data'))
	} finally {
		loading.value = false
	}
}

/**
 * Trips with their bookings, derived span, types and period. Computed once and
 * shared: the Trips grid filters and sorts these, and the trip picker reads the
 * same rows, so both describe a trip identically.
 */
export const allTripRows = computed(() => tripRows(trips.value, bookings.value))

/**
 * Trip names by id, so the Bookings grid can render and sort on the name rather
 * than the foreign key. Built once per change instead of scanned per row.
 */
export const tripNames = computed(() => Object.fromEntries(
	// Decoded, so a leftover HTML entity sorts and reads as the character it is.
	trips.value.map((trip) => [trip.id, decodeHtmlEntities(trip.name)]),
))

/** Only offer type filters that exist — an empty "Car rental" filter is a dead end. */
export const availableTypes = computed(() => bookingTypes(bookings.value))

// Navigation counters: what awaits you, not totals.
export const draftBookingCount = computed(() => draftCount(bookings.value))
export const attentionCount = computed(() => needsAttention(messages.value).length)
