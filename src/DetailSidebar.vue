<script setup lang="ts">
import { computed } from 'vue'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { t } from '@nextcloud/l10n'
import type { Booking, Message, Trip } from './api'
import type { DetailType } from './detail'
import BookingDetails from './BookingDetails.vue'
import { bookingsFromMessage, byId, messageForBooking, relatedBookings } from './detail'
import { bookingSpan } from './bookings'
import { formatSpan, formatTimestamp } from './grid'
import { inTravelOrder, tripSpan } from './trips'
import { messageStatusLabel } from './messages'

/**
 * The one place a booking, trip or message is shown in full — openable from any
 * view, and later from the calendar. Its job beyond display is the *trail*: every
 * panel names the things it is connected to and offers to open them, so you can
 * always get from a booking back to the email that produced it.
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

const title = computed(() => {
	if (booking.value !== null) {
		return booking.value.title || booking.value.type
	}
	if (trip.value !== null) {
		return trip.value.name
	}
	if (message.value !== null) {
		return message.value.subject || t('travelmanager', '(no subject)')
	}
	return t('travelmanager', 'Not found')
})

const subtitle = computed(() => {
	if (booking.value !== null) {
		return [booking.value.provider, bookingSpan(booking.value)].filter(Boolean).join(' · ')
	}
	if (trip.value !== null) {
		const span = tripSpan(tripBookings.value)
		return formatSpan(span.start, span.end)
	}
	if (message.value !== null) {
		return [message.value.sender, formatTimestamp(message.value.sentAt)].filter(Boolean).join(' · ')
	}
	return ''
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

const bookingLabel = (item: Booking): string => item.title || item.type
</script>

<template>
	<NcAppSidebar :name="title"
		:subname="subtitle"
		@update:open="emit('close')">
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

		<template v-else-if="booking !== null">
			<BookingDetails :booking="booking" />
			<h4 :class="$style.heading">
				{{ t('travelmanager', 'Related') }}
			</h4>
			<ul :class="$style.links">
				<li v-if="bookingTrip !== null">
					<NcButton variant="tertiary" @click="emit('open', 'trip', bookingTrip.id)">
						{{ t('travelmanager', 'Trip') }}: {{ bookingTrip.name }}
					</NcButton>
				</li>
				<!-- Only offered when the message is loaded; the parent fetches it on
				     demand for bookings older than the message list's page. -->
				<li v-if="bookingMessage !== null">
					<NcButton variant="tertiary" @click="emit('open', 'message', bookingMessage.id)">
						{{ t('travelmanager', 'Source email') }}: {{ bookingMessage.subject || bookingMessage.messageId }}
					</NcButton>
				</li>
				<li v-if="bookingTrip === null && bookingMessage === null" :class="$style.none">
					{{ t('travelmanager', 'Nothing linked to this booking yet.') }}
				</li>
			</ul>
		</template>

		<template v-else-if="trip !== null">
			<h4 :class="$style.heading">
				{{ t('travelmanager', 'Bookings') }}
			</h4>
			<ul :class="$style.links">
				<li v-for="item in tripBookings" :key="item.id">
					<NcButton variant="tertiary" @click="emit('open', 'booking', item.id)">
						{{ bookingLabel(item) }}
					</NcButton>
				</li>
				<li v-if="tripBookings.length === 0" :class="$style.none">
					{{ t('travelmanager', 'No bookings linked to this trip yet.') }}
				</li>
			</ul>
		</template>

		<template v-else-if="message !== null">
			<dl :class="$style.fields">
				<dt>{{ t('travelmanager', 'Status') }}</dt>
				<dd>{{ messageStatusLabel(message.status) }}</dd>
				<dt>{{ t('travelmanager', 'Received') }}</dt>
				<dd>{{ formatTimestamp(message.sentAt) }}</dd>
				<dt>{{ t('travelmanager', 'Last processed') }}</dt>
				<dd>{{ formatTimestamp(message.processedAt) }}</dd>
				<dt>{{ t('travelmanager', 'Attempts') }}</dt>
				<dd>{{ message.attempts }}</dd>
			</dl>
			<h4 :class="$style.heading">
				{{ t('travelmanager', 'Bookings from this email') }}
			</h4>
			<ul :class="$style.links">
				<li v-for="item in messageBookings" :key="item.id">
					<NcButton variant="tertiary" @click="emit('open', 'booking', item.id)">
						{{ bookingLabel(item) }}
					</NcButton>
				</li>
				<li v-if="messageBookings.length === 0" :class="$style.none">
					{{ t('travelmanager', 'This email did not create a booking.') }}
				</li>
			</ul>

			<!-- Bookings this email is about but did not create: the 'related'
			     status, where an earlier email already made the booking. -->
			<template v-if="messageRelated.length > 0">
				<h4 :class="$style.heading">
					{{ t('travelmanager', 'Relates to') }}
				</h4>
				<ul :class="$style.links">
					<li v-for="item in messageRelated" :key="item.id">
						<NcButton variant="tertiary" @click="emit('open', 'booking', item.id)">
							{{ bookingLabel(item) }}
						</NcButton>
					</li>
				</ul>
			</template>
		</template>
	</NcAppSidebar>
</template>

<style module>
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

.none {
	color: var(--color-text-maxcontrast);
}

.fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 1px 12px;
	align-items: baseline;
	margin: 0;
	line-height: 1.4;
}

.fields dt {
	color: var(--color-text-maxcontrast);
}

.fields dd {
	margin: 0;
	min-width: 0;
	overflow-wrap: anywhere;
}
</style>
