<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import type { Booking } from './api'
import {
	bookingHeaderFields,
	carFields,
	flightSegmentFields,
	hotelFields,
	passengerLines,
} from './bookings'

/**
 * A booking's type-specific body: passengers and legs for a flight, the stay for
 * accommodation, the hire for a car, plus the cross-type fields that are not
 * already grid columns.
 *
 * Extracted so the expanded grid row and the detail sidebar render a booking
 * identically — the two would otherwise drift, and a flight's legs are the most
 * intricate markup in the app.
 */
defineProps<{ booking: Booking }>()
</script>

<template>
	<div>
		<!-- Only what is not already a column in the Bookings grid. -->
		<div v-if="bookingHeaderFields(booking).length > 0" :class="$style.fields">
			<template v-for="field in bookingHeaderFields(booking)" :key="field.label">
				<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
				<span :class="$style.fieldValue">{{ field.value }}</span>
			</template>
		</div>

		<!-- Flight: passengers + one row per leg -->
		<template v-if="booking.type === 'flight'">
			<div v-if="passengerLines(booking.details).length > 0" :class="[$style.fields, $style.typeFields]">
				<span :class="$style.fieldLabel">{{ t('travelmanager', 'Passengers') }}</span>
				<span :class="$style.fieldValue">
					<span v-for="(line, i) in passengerLines(booking.details)" :key="i" :class="$style.passenger">
						{{ line }}
					</span>
				</span>
			</div>
			<ul :class="$style.segments">
				<li v-for="(seg, i) in (booking.details.segments ?? [])" :key="i" :class="$style.segment">
					<span v-if="(booking.details.segments ?? []).length > 1" :class="$style.segmentIndex">
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
		<div v-else-if="booking.type === 'car_rental'" :class="[$style.fields, $style.typeFields]">
			<template v-for="field in carFields(booking.details)" :key="field.label">
				<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
				<span :class="$style.fieldValue">{{ field.value }}</span>
			</template>
		</div>
		<div v-else-if="booking.type === 'accommodation'" :class="[$style.fields, $style.typeFields]">
			<template v-for="field in hotelFields(booking.details)" :key="field.label">
				<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
				<span :class="$style.fieldValue">{{ field.value }}</span>
			</template>
		</div>
	</div>
</template>

<style module>
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

/* No rule above the first leg — it would read as a divider from the passengers
   rather than as a separator between legs. */
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
</style>
