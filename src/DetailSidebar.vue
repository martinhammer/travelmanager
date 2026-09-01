<script setup lang="ts">
import { computed } from 'vue'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { Booking, Message, ReviewState, Trip } from './api'
import { setBookingReviewState } from './api'
import type { DetailType } from './detail'
import BookingDetails from './BookingDetails.vue'
import { bookingsFromMessage, byId, messageForBooking, relatedBookings } from './detail'
import { bookingSpan, reviewActions } from './bookings'
import {
	askDeleteBooking,
	askDeleteTrip,
	openLinkDialog,
	openTripEditor,
	openTripPicker,
} from './dialogs'
import {
	actionLabel,
	bookingLabel,
	bookingStatusLabel,
	reviewLabels,
	reviewStateLabel,
	tripLabel,
	tripTypeLabel,
	typeName,
} from './labels'
import { formatSpan, formatTimestamp } from './grid'
import { reload } from './store'
import { inTravelOrder, tripSpan } from './trips'
import { messageStatusLabel } from './messages'

/**
 * The one place a booking, trip or message is shown in full — openable from any
 * view, and later from the calendar. Its job beyond display is the *trail*: every
 * panel names the things it is connected to and offers to open them, so you can
 * always get from a booking back to the email that produced it.
 *
 * It is also where a booking or a trip is *acted on*. Bookings and Trips rows no
 * longer expand: the row body was a poorer copy of this panel, and duplicating it
 * meant two places to keep a field in step — and the one that most users saw had
 * no cross-links at all, which is exactly the trail this panel exists to keep.
 * (Messages rows still expand; their body is a wide diagnostic pane, not a second
 * copy of the card, and a 20k-character model response is unreadable in here.)
 */
const props = defineProps<{
	type: DetailType
	id: number
	bookings: Booking[]
	trips: Trip[]
	messages: Message[]
	/** Label of wherever this was opened from, for the back button. */
	backLabel: string | null
}>()

const emit = defineEmits<{
	close: []
	back: []
	open: [type: DetailType, id: number]
}>()

const booking = computed(() => props.type === 'booking' ? byId(props.bookings, props.id) : null)
const trip = computed(() => props.type === 'trip' ? byId(props.trips, props.id) : null)
const message = computed(() => props.type === 'message' ? byId(props.messages, props.id) : null)

/** The entity the route points at, whichever kind it is. */
const found = computed(() => booking.value ?? trip.value ?? message.value)

/** The colour to mark the panel heading with, if this is a trip that has one. */
const headerColor = computed(() => trip.value?.color ?? null)

const title = computed(() => {
	// Through the label helpers, so a stray HTML entity in an extracted title
	// reads as its character here exactly as it does in the grid.
	if (booking.value !== null) {
		return bookingLabel(booking.value)
	}
	if (trip.value !== null) {
		return tripLabel(trip.value)
	}
	if (message.value !== null) {
		return message.value.subject || t('travelmanager', '(no subject)')
	}
	return t('travelmanager', 'Not found')
})

/** A label/value pair, dropped when the value is empty. */
interface Field { label: string, value: string }

const field = (label: string, value: string | number | null | undefined): Field | null =>
	value === null || value === undefined || value === '' ? null : { label, value: String(value) }

/**
 * The entity's own attributes, shown the same way as its detail fields.
 *
 * The panel has no grid row above it, so it carries everything a column would —
 * squeezing them onto one subtitle line under the title made them unreadable and
 * inconsistent with the label/value pairs immediately below.
 */
const headerFields = computed<Field[]>(() => {
	const item = booking.value
	if (item !== null) {
		return [
			field(t('travelmanager', 'Type'), typeName(item.type)),
			field(t('travelmanager', 'Status'), [
				reviewStateLabel(item.reviewState),
				// Only when it isn't the plain case: the two axes are orthogonal.
				item.status === 'active' ? null : bookingStatusLabel(item.status),
			].filter(Boolean).join(' · ')),
			field(item.type === 'car_rental'
				? t('travelmanager', 'Supplier')
				: t('travelmanager', 'Provider'), item.provider),
			field(t('travelmanager', 'Booking reference'), item.bookingReference),
			field(t('travelmanager', 'Confirmation number'), item.confirmationNumber),
			field(t('travelmanager', 'Travel dates'), bookingSpan(item)),
		].filter((f): f is Field => f !== null)
	}
	if (trip.value !== null) {
		const span = tripSpan(tripBookings.value)
		return [
			// Empty for an unclassified trip, and `field` drops empties — so the row
			// is absent rather than saying "Type: —".
			field(t('travelmanager', 'Type'), tripTypeLabel(trip.value.type)),
			field(t('travelmanager', 'Travel dates'), formatSpan(span.start, span.end)),
			field(t('travelmanager', 'Bookings'), tripBookings.value.length),
		].filter((f): f is Field => f !== null)
	}
	if (message.value !== null) {
		return [
			field(t('travelmanager', 'From'), message.value.sender),
			field(t('travelmanager', 'Status'), messageStatusLabel(message.value.status)),
			field(t('travelmanager', 'Received'), formatTimestamp(message.value.sentAt)),
			field(t('travelmanager', 'Last processed'), formatTimestamp(message.value.processedAt)),
			field(t('travelmanager', 'Attempts'), message.value.attempts),
		].filter((f): f is Field => f !== null)
	}
	return []
})

// --- the trail ------------------------------------------------------------

const bookingTrip = computed(() =>
	booking.value?.tripId == null ? null : byId(props.trips, booking.value.tripId))

const bookingMessage = computed(() =>
	booking.value === null ? null : messageForBooking(props.messages, booking.value))

const tripBookings = computed(() =>
	trip.value === null ? [] : inTravelOrder(props.bookings.filter((b) => b.tripId === trip.value?.id)))

const messageBookings = computed(() =>
	message.value === null ? [] : bookingsFromMessage(props.bookings, message.value))

// The 'related' case: bookings this email is about but did not create. Kept
// separate from the list above — "made this" and "is about this" are different
// claims, and conflating them would misreport what the app actually did.
const messageRelated = computed(() =>
	message.value === null ? [] : relatedBookings(props.bookings, message.value))

// --- acting on it ----------------------------------------------------------

const onReview = async (id: number, target: ReviewState) => {
	try {
		await setBookingReviewState(id, target)
		showSuccess(reviewLabels[target].done)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not update the booking'))
	}
}

/**
 * Confirming a *draft* asks which trip it belongs to first; every other review
 * transition applies straight away. Restoring a discarded booking also targets
 * 'confirmed', but it is not a first decision and may already have a trip — hence
 * the reviewState check rather than a target check alone.
 * @param item the booking being acted on
 * @param target the review state the button moves it to
 */
const onReviewAction = (item: Booking, target: ReviewState) => {
	if (target === 'confirmed' && item.reviewState === 'draft') {
		openTripPicker(item)
		return
	}
	onReview(item.id, target)
}

/**
 * Archiving and discarding are never the obvious next thing to do, so they never
 * take the primary slot; confirming and restoring are. Discarding is the one
 * that throws work away — it is reversible, so not a destructive *confirmation*,
 * but it still warrants the warning colour rather than sitting quietly beside
 * Archive.
 * @param target the review state the button moves the booking to
 */
const reviewVariant = (target: ReviewState): 'primary' | 'secondary' | 'error' => {
	if (target === 'discarded') {
		return 'error'
	}
	return target === 'archived' ? 'secondary' : 'primary'
}
</script>

<template>
	<!-- The trip's colour rides on the heading rather than sitting in a field of
	     its own: it identifies the trip, exactly as it does in front of the name
	     on both grids, and "Colour: #bf678b" is a hex nobody needs to read. Drawn
	     with a ::before on Nextcloud's own heading row (see .tm-sidebar-swatch in
	     grid.css) because the panel takes its name as a string. -->
	<NcAppSidebar :name="title"
		:class="{ 'tm-sidebar-swatch': headerColor !== null }"
		:style="{ '--tm-sidebar-swatch': headerColor ?? undefined }"
		@update:open="emit('close')">
		<!-- The sidebar's slot runs flush to its edge; the title above it is
		     inset. Half that inset keeps the text and lozenges off the border
		     without pretending to line up with the heading. -->
		<div :class="$style.body">
			<!-- The trail back, when this was opened from somewhere else. Uses the
			     browser's own history, so it stays in step with the Back button. -->
			<NcButton v-if="backLabel !== null"
				:class="$style.back"
				variant="tertiary"
				@click="emit('back')">
				← {{ backLabel }}
			</NcButton>

			<NcEmptyContent v-if="found === null"
				:name="t('travelmanager', 'Not found')"
				:description="t('travelmanager', 'This item no longer exists, or was deleted.')" />

			<!-- The entity's own attributes and its type-specific fields in one
			     grid, so a single label column serves the whole panel — see
			     .tm-fields-group in grid.css. -->
			<div v-else class="tm-fields-group">
				<template v-for="entry in headerFields" :key="entry.label">
					<span class="tm-field-label">{{ entry.label }}</span>
					<span class="tm-field-value">{{ entry.value }}</span>
				</template>
				<BookingDetails v-if="booking !== null" :booking="booking" />
			</div>

			<template v-if="booking !== null">
				<h4 :class="$style.heading">
					{{ t('travelmanager', 'Related') }}
				</h4>
				<ul :class="$style.links">
					<!-- Lozenge then link, as in the booking lists: the lozenge says what
					     kind of thing this is, the link says which one. -->
					<li v-if="bookingTrip !== null" :class="$style.linkRow">
						<span class="tm-badge">{{ t('travelmanager', 'Trip') }}</span>
						<NcButton variant="tertiary" @click="emit('open', 'trip', bookingTrip.id)">
							{{ tripLabel(bookingTrip) }}
						</NcButton>
					</li>
					<!-- Only offered when the message is loaded; the parent fetches it on
					     demand for bookings older than the message list's page. -->
					<li v-if="bookingMessage !== null" :class="$style.linkRow">
						<span class="tm-badge">{{ t('travelmanager', 'Source email') }}</span>
						<NcButton variant="tertiary" @click="emit('open', 'message', bookingMessage.id)">
							{{ bookingMessage.subject || bookingMessage.messageId }}
						</NcButton>
					</li>
					<li v-if="bookingTrip === null && bookingMessage === null" :class="$style.none">
						{{ t('travelmanager', 'Nothing linked to this booking yet.') }}
					</li>
				</ul>

				<h4 :class="$style.heading">
					{{ t('travelmanager', 'Actions') }}
				</h4>
				<div class="tm-actions">
					<!-- Primary while the booking has no trip — filing it is then the
					     one thing still outstanding; secondary once it has one, where
					     the button is a way back in rather than a task. -->
					<NcButton v-if="booking.reviewState === 'confirmed'"
						:variant="booking.tripId === null ? 'primary' : 'secondary'"
						@click="openTripPicker(booking)">
						{{ t('travelmanager', 'Trip') }}
					</NcButton>
					<NcButton v-for="target in reviewActions(booking)"
						:key="target"
						:variant="reviewVariant(target)"
						@click="onReviewAction(booking, target)">
						{{ actionLabel(booking, target) }}
					</NcButton>
					<NcButton v-if="booking.reviewState === 'discarded' || booking.reviewState === 'archived'"
						variant="error"
						@click="askDeleteBooking(booking)">
						{{ t('travelmanager', 'Delete permanently') }}
					</NcButton>
				</div>
			</template>

			<template v-else-if="trip !== null">
				<h4 :class="$style.heading">
					{{ t('travelmanager', 'Bookings') }}
				</h4>
				<ul :class="$style.links">
					<li v-for="item in tripBookings" :key="item.id" :class="$style.linkRow">
						<span class="tm-badge">{{ typeName(item.type) }}</span>
						<NcButton variant="tertiary" @click="emit('open', 'booking', item.id)">
							{{ bookingLabel(item) }}
						</NcButton>
					</li>
					<li v-if="tripBookings.length === 0" :class="$style.none">
						{{ t('travelmanager', 'No bookings linked to this trip yet.') }}
					</li>
				</ul>

				<h4 :class="$style.heading">
					{{ t('travelmanager', 'Actions') }}
				</h4>
				<div class="tm-actions">
					<NcButton variant="secondary" @click="openTripEditor(trip)">
						{{ t('travelmanager', 'Edit') }}
					</NcButton>
					<NcButton variant="secondary" @click="openLinkDialog(trip)">
						{{ t('travelmanager', 'Bookings') }}
					</NcButton>
					<NcButton variant="error" @click="askDeleteTrip(trip)">
						{{ t('travelmanager', 'Delete') }}
					</NcButton>
				</div>
			</template>

			<template v-else-if="message !== null">
				<h4 :class="$style.heading">
					{{ t('travelmanager', 'Bookings from this email') }}
				</h4>
				<ul :class="$style.links">
					<li v-for="item in messageBookings" :key="item.id" :class="$style.linkRow">
						<span class="tm-badge">{{ typeName(item.type) }}</span>
						<NcButton variant="tertiary" @click="emit('open', 'booking', item.id)">
							{{ bookingLabel(item) }}
						</NcButton>
					</li>
					<li v-if="messageBookings.length === 0" :class="$style.none">
						{{ t('travelmanager', 'This email did not create a booking.') }}
					</li>
				</ul>

				<!-- Bookings this email is about but did not create: either the
				     'related' status, where an earlier email already made the
				     booking, or one the email's own booking may duplicate. -->
				<template v-if="messageRelated.length > 0">
					<h4 :class="$style.heading">
						{{ t('travelmanager', 'Relates to') }}
					</h4>
					<ul :class="$style.links">
						<li v-for="item in messageRelated" :key="item.id" :class="$style.linkRow">
							<span class="tm-badge">{{ typeName(item.type) }}</span>
							<NcButton variant="tertiary" @click="emit('open', 'booking', item.id)">
								{{ bookingLabel(item) }}
							</NcButton>
						</li>
					</ul>
				</template>
			</template>
		</div>
	</NcAppSidebar>
</template>

<style module>
.body {
	padding-inline-start: 10px;

	/* The panel scrolls itself (`.app-sidebar` is overflow-y: auto), and a scroll
	   container's last child otherwise ends flush against the bottom edge — which
	   is where the Actions buttons sit. The calendar made this visible: the app
	   content used to scroll the page, so the sidebar rarely scrolled on its own. */
	padding-block-end: 16px;
}

.back {
	margin-bottom: 12px;
}

.heading {
	font-size: 1em;
	color: var(--color-text-maxcontrast);
	margin: 20px 0 4px;
}

.links {
	list-style: none;
	margin: 0;
	padding: 0;
}

/* Lozenge then link. The lozenge never shrinks — the title is the thing with
   room to give. */
.linkRow {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.linkRow > *:first-child {
	flex-shrink: 0;
}

.none {
	color: var(--color-text-maxcontrast);
}

</style>
