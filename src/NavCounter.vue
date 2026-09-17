<script setup lang="ts">
import { computed } from 'vue'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'

/**
 * One navigation row's count: what awaits you, over how much there is.
 *
 * **Two numbers, and only one of them is a call to action.** The lozenge is the
 * outstanding work — Mail's convention, and the reason a bubble draws the eye at
 * all — while the total is context, so it stays muted text beside it rather than
 * competing for the same emphasis. A muted number therefore means the same thing
 * on every row, which is what lets the Trips row show a total alone without
 * looking like it lost half its counter.
 *
 * **Zero outstanding renders as the total alone**, not as a `0` bubble and not as
 * a bare "/ 14". A row with nothing to do should look like a row with nothing to
 * do, and a leading slash with nothing in front of it reads as a rendering fault.
 */
const props = defineProps<{
	/** Rows awaiting the user. Omit on a row where nothing is ever outstanding. */
	attention?: number
	/** How many there are in total. */
	total: number
	/**
	 * What the pair means, as a sentence — the tooltip, and the accessible name.
	 * Two bare numbers read as "3 14" to a screen reader, and the slash between
	 * them is punctuation nobody announces. Worded by the caller because only it
	 * knows whether the lozenge counts drafts or failures.
	 */
	label: string
}>()

// Undefined (a row with no queue) and 0 (a queue that is empty) render the same:
// the total, on its own. They differ only in that the first can never show a
// lozenge at all.
const outstanding = computed(() => (props.attention ?? 0) > 0)
</script>

<template>
	<span :class="$style.counter" :title="label" :aria-label="label">
		<template v-if="outstanding">
			<NcCounterBubble :count="attention ?? 0" type="highlighted" />
			<span :class="$style.total" aria-hidden="true">/ {{ total }}</span>
		</template>
		<span v-else :class="$style.total" aria-hidden="true">{{ total }}</span>
	</span>
</template>

<style module>
.counter {
	display: flex;
	align-items: center;
	gap: 4px;
}

/* The total is context, not a task: it recedes so the lozenge beside it is the
   only thing on the row asking for anything. */
.total {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
}
</style>
