<script setup lang="ts">
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue'
import { t } from '@nextcloud/l10n'
import { sortMarker } from './grid'

/**
 * The column headings, as a menu, for when the grid is too narrow to show them.
 *
 * Built from the view's own column list and wired to its own `onSortColumn`, so
 * it adds no sort logic: picking the active column again flips it — or, for
 * Bookings' Travel dates, steps through its three modes — exactly as clicking
 * that heading does. Shown only in the compact layout (`.tm-sort-menu` in
 * grid.css), where the heading row is hidden.
 */
const props = defineProps<{
	columns: { key: string, label: string }[]
	sort: string
	direction: string
	/** Words for the current order, where asc/desc would misdescribe it. */
	describe?: string
}>()

const emit = defineEmits<{ select: [key: string] }>()

// The ▲/▼/◆ marker is a glyph, so the order is also said in words — on the
// checked item and in the trigger's name, since nothing else announces it.
const directionText = computed((): string => {
	if (props.describe) {
		return props.describe
	}
	switch (props.direction) {
	case 'asc':
		return t('travelmanager', 'Ascending')
	case 'desc':
		return t('travelmanager', 'Descending')
	default:
		return ''
	}
})

const triggerLabel = computed((): string => {
	const column = props.columns.find((c) => c.key === props.sort)?.label ?? ''
	return directionText.value
		? t('travelmanager', 'Sort by {column}: {direction}', { column, direction: directionText.value })
		: t('travelmanager', 'Sort by {column}', { column })
})
</script>

<template>
	<!-- A wrapper of our own carries the class, so grid.css can show and hide it
	     without having to outrank NcActions' scoped display rule. -->
	<span class="tm-sort-menu">
		<NcActions :aria-label="triggerLabel" :title="triggerLabel">
			<template #icon>
				<SortVariantIcon :size="20" />
			</template>
			<!-- Radio items, so a screen reader hears which column is checked.
			     @click rather than update:modelValue: the radio only emits on a
			     change, and picking the checked column again has to flip it. -->
			<NcActionButton v-for="column in columns"
				:key="column.key"
				type="radio"
				:model-value="sort"
				:value="column.key"
				:description="sort === column.key ? directionText : undefined"
				close-after-click
				@click="emit('select', column.key)">
				<template #icon>
					<span class="tm-sort-menu-marker" aria-hidden="true">
						{{ sortMarker(sort, column.key, direction) }}
					</span>
				</template>
				{{ column.label }}
			</NcActionButton>
		</NcActions>
	</span>
</template>
