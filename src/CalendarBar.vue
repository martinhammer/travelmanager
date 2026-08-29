<script setup lang="ts">
import { type Component, computed } from 'vue'
import AirplaneIcon from 'vue-material-design-icons/Airplane.vue'
import BagSuitcaseIcon from 'vue-material-design-icons/BagSuitcase.vue'
import BedIcon from 'vue-material-design-icons/Bed.vue'
import CarIcon from 'vue-material-design-icons/Car.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import type { CalendarItem } from './calendar'
import { contrastingText } from './calendar'
import { formatSpan } from './grid'
import { reviewStateLabel, typeName } from './labels'

/**
 * One bar on the month grid: a trip or a booking, across the days it covers.
 *
 * Positioned by its parent — CalendarView passes `grid-column` / `grid-row` as an
 * inline style, because only the layout knows which lane the bar landed in. The
 * bar itself owns what it *looks* like: its type colour, its icon, and the
 * draft-versus-confirmed cue.
 *
 * Type is signalled by icon as well as colour, and draft by a dashed outline
 * rather than a lighter shade, so neither cue depends on seeing colour.
 */
const props = defineProps<{
	item: CalendarItem
	/** It began before this week — draw the leading edge open. */
	continuesLeft: boolean
	/** It runs on past this week — draw the trailing edge open. */
	continuesRight: boolean
	/** It is the one the detail panel is showing. */
	selected: boolean
	/** Where it goes. See the note on the template about why this is a link. */
	href: string
}>()

// MapMarker is the fallback rather than a blank: an unknown booking type is
// still something that happens somewhere on a day, and a bar with no icon at all
// would be unreadable once the label is dropped at narrow widths.
const ICONS: Record<string, Component> = {
	flight: AirplaneIcon,
	accommodation: BedIcon,
	car_rental: CarIcon,
}

const icon = computed(() => props.item.kind === 'trip'
	? BagSuitcaseIcon
	: (ICONS[props.item.type ?? ''] ?? MapMarkerIcon))

/**
 * The trip's colour, when this bar belongs to one.
 *
 * Only the raw colour is handed over; **paling is left to CSS**, because a paler
 * version means one mixed toward the page background and only the stylesheet
 * knows what that is — mixing toward white here would come out right on a light
 * theme and glaring on a dark one.
 *
 * A trip bar carries the colour outright, so its text has to be computed: a trip
 * colour comes from Nextcloud's picker, which offers pale yellows that white
 * would vanish on. A booking bar is pale by construction and takes the page's own
 * text colour, set in calendar.css.
 *
 * Null leaves `--tm-cal-trip` at its default, the theme's own accent, so an
 * unfiled booking and one in an uncoloured trip look like part of the app rather
 * than like a third thing. **Colour on this view means one thing only: which
 * trip.** What kind of booking it is, is carried by the icon — which is why every
 * bar has one.
 */
const tint = computed(() => {
	const { color, kind } = props.item
	if (color === null) {
		return {}
	}
	return kind === 'trip'
		? { '--tm-cal-trip': color, '--tm-cal-text': contrastingText(color) }
		: { '--tm-cal-trip': color }
})

/**
 * The bar's accessible name, and its tooltip.
 *
 * Not optional: the visible label is `display: none` at narrow widths, which
 * takes it out of the accessibility tree too, so the button would otherwise be
 * an unnamed control exactly when it is hardest to identify by sight.
 */
const description = computed(() => {
	const { item } = props
	if (item.kind === 'trip') {
		return item.label
	}
	return [
		item.label,
		typeName(item.type ?? ''),
		item.reviewState === null ? null : reviewStateLabel(item.reviewState),
		formatSpan(item.start, item.end) || null,
	].filter(Boolean).join(' · ')
})
</script>

<template>
	<!--
		An anchor, not a button, and not for looks: Nextcloud's core stylesheet
		styles every bare `button` — `min-height: var(--default-clickable-area)`,
		`margin: 3px`, and hover rules that repaint background and border — from
		selectors up to (0,4,1) specificity, which a sane app-side class cannot
		outrank without absurdity. It styles bare anchors not at all.

		It is also the honest element: clicking a bar navigates to a route, so it
		gets keyboard focus, a status-bar preview and middle-click-to-new-tab for
		free. The click is still handled in JS (`@click.prevent` in the view),
		because our router listens for popstate and a fragment link fires only
		hashchange.
	-->
	<a :href="href"
		:style="tint"
		class="tm-cal-bar"
		:class="{
			'tm-cal-bar-draft': item.reviewState === 'draft',
			'tm-cal-bar-continues-left': continuesLeft,
			'tm-cal-bar-continues-right': continuesRight,
			'tm-cal-bar-selected': selected,
		}"
		:data-kind="item.kind"
		:title="description"
		:aria-label="description">
		<component :is="icon" class="tm-cal-bar-icon" :size="12" />
		<span class="tm-cal-bar-label">{{ item.label }}</span>
	</a>
</template>
