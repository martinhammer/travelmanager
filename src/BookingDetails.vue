<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { Booking } from './api'
import { carFields, flightSegmentFields, hotelFields, passengerLines } from './bookings'

/**
 * A booking's **type-specific** body: passengers and legs for a flight, the stay
 * for accommodation, the hire for a car.
 *
 * Deliberately no cross-type fields (type, provider, reference…): what is worth
 * repeating differs by caller. A grid row already shows them as columns and only
 * adds the confirmation number; the detail panel has no row above it and shows
 * the lot. Each caller renders its own header block above this one.
 *
 * Every wrapper here carries a `tm-` class rather than a scoped one, so a parent
 * marked `.tm-fields-group` can flatten them with `display: contents` and share
 * one label column across the whole panel — see grid.css. Separators are explicit
 * elements for the same reason: a flattened wrapper has no box to put a border on.
 */
const props = defineProps<{ booking: Booking }>()

const passengers = computed(() => passengerLines(props.booking.details))
const segments = computed(() => props.booking.details.segments ?? [])
</script>

<template>
	<div class="tm-block-group">
		<!-- Flight: passengers + one block per leg -->
		<template v-if="booking.type === 'flight'">
			<div v-if="passengers.length > 0" class="tm-fields tm-block">
				<span class="tm-field-label">{{ t('travelmanager', 'Passengers') }}</span>
				<span class="tm-field-value">
					<span v-for="(line, i) in passengers" :key="i" :class="$style.passenger">
						{{ line }}
					</span>
				</span>
			</div>
			<template v-for="(seg, i) in segments" :key="i">
				<!-- A rule between legs, plain space before the first: a line above
				     leg 1 would read as a divider from the passengers rather than as a
				     separator between legs. -->
				<span v-if="i === 0" class="tm-gap" aria-hidden="true" />
				<span v-else class="tm-divider" aria-hidden="true" />
				<span v-if="segments.length > 1" class="tm-segment-index">
					{{ t('travelmanager', 'Leg {n}', { n: i + 1 }) }}
				</span>
				<div class="tm-fields">
					<template v-for="field in flightSegmentFields(seg)" :key="field.label">
						<span class="tm-field-label">{{ t('travelmanager', field.label) }}</span>
						<span class="tm-field-value">{{ field.value }}</span>
					</template>
				</div>
			</template>
		</template>

		<!-- Car rental / accommodation: a single labelled detail block -->
		<div v-else-if="booking.type === 'car_rental'" class="tm-fields tm-block">
			<template v-for="field in carFields(booking.details)" :key="field.label">
				<span class="tm-field-label">{{ t('travelmanager', field.label) }}</span>
				<span class="tm-field-value">{{ field.value }}</span>
			</template>
		</div>
		<div v-else-if="booking.type === 'accommodation'" class="tm-fields tm-block">
			<template v-for="field in hotelFields(booking.details)" :key="field.label">
				<span class="tm-field-label">{{ t('travelmanager', field.label) }}</span>
				<span class="tm-field-value">{{ field.value }}</span>
			</template>
		</div>
	</div>
</template>

<style module>
.passenger {
	display: block;
}
</style>
