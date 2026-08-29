<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { getDayNamesMin, getFirstDay, getMonthNames, t } from '@nextcloud/l10n'
import CalendarBar from './CalendarBar.vue'
import type { CalendarItem, LaneMetrics } from './calendar'
import {
	DEFAULT_MAX_LANES,
	lanesForHeight,
	addMonths,
	bookingItems,
	calendarBookings,
	firstDraft,
	isInMonth,
	layoutMonth,
	monthOf,
	monthSummary,
	monthWeeks,
	tripItem,
	weekdayLabels,
} from './calendar'
import { localDate } from './grid'
import { bookingLabel, tripLabel } from './labels'
import { detailHref, isOpen, openDetail } from './navigation'
import { allTripRows, bookings, loading } from './store'

/**
 * The month grid — the app's default view, and the overview the others hang off.
 *
 * Clicking a bar opens the detail sidebar **without leaving the calendar** (see
 * keepsView in detail.ts): the month is what you are working from, so taking it
 * off screen on every click would make stepping through a trip a round trip
 * through the Bookings list each time. The sidebar shrinks the content area
 * rather than covering it, and because every bar's day and lane are fixed by its
 * dates, that shrink is a horizontal squeeze — nothing moves to another day.
 *
 * Trips and bookings are both drawn as bars across the days they actually cover,
 * so a five-night stay looks like five nights. The layout itself is pure and
 * lives in calendar.ts; this file only supplies the locale, the wording and the
 * click targets.
 */

// The locale's own week shape. Read once: it cannot change without a reload.
const firstDay = getFirstDay()
const weekdays = weekdayLabels(getDayNamesMin(), firstDay)
const monthNames = getMonthNames()

const month = ref(monthOf())

/**
 * Discarded and archived are soft states whose rows survive, but they are not
 * travel you are doing — on a list they merely sit there, whereas here they would
 * take lanes from the bookings that matter. Off by default, one click away.
 */
const showAll = ref(false)

// Recomputed rather than stored, so "today" is right if the page is left open
// across midnight and the user pages back to this month.
const today = computed(() => localDate())

const items = computed<CalendarItem[]>(() => {
	const trips = allTripRows.value
		.map((row) => tripItem(row, tripLabel(row.trip)))
	// flatMap, because a multi-leg flight draws one bar per leg.
	const placed = calendarBookings(bookings.value, showAll.value)
		.flatMap((booking) => bookingItems(booking, bookingLabel(booking)))
	return [...trips.filter((item): item is CalendarItem => item !== null), ...placed]
})

const weeks = computed(() => monthWeeks(month.value, firstDay))

/**
 * Weeks the user has asked to see in full. Expanding in place beats a popover:
 * the hidden items stay on the days they belong to, and there is no floating
 * layer to dismiss.
 */
const expanded = ref<number[]>([])

/**
 * How many lanes fit, measured rather than assumed.
 *
 * The grid's height is set by the window, not by its contents (it is the flex
 * child that takes the leftover and scrolls), so observing it is stable — the
 * lane count changes the content, never the container, so there is no loop.
 */
const grid = ref<HTMLElement | null>(null)
const gridHeight = ref(0)
const metrics = ref<LaneMetrics | null>(null)
let observer: ResizeObserver | null = null

onMounted(() => {
	const el = grid.value
	if (el === null) {
		return
	}
	// The geometry lives in calendar.css; read it back rather than restating it,
	// so a change to the bar height cannot silently break the arithmetic.
	const style = getComputedStyle(el)
	const px = (name: string): number => Number.parseFloat(style.getPropertyValue(name))
	metrics.value = {
		head: px('--tm-cal-head'),
		foot: px('--tm-cal-foot'),
		bar: px('--tm-cal-bar-height'),
		gap: px('--tm-cal-lane-gap'),
	}
	observer = new ResizeObserver(() => {
		gridHeight.value = el.clientHeight
	})
	observer.observe(el)
	gridHeight.value = el.clientHeight
})

onUnmounted(() => observer?.disconnect())

const laneCapacity = computed(() => {
	const shape = metrics.value
	if (shape === null || gridHeight.value === 0 || weeks.value.length === 0) {
		return DEFAULT_MAX_LANES
	}
	return lanesForHeight(gridHeight.value / weeks.value.length, shape)
})

const layouts = computed(() => layoutMonth(
	items.value,
	weeks.value,
	(index) => expanded.value.includes(index) ? Number.MAX_SAFE_INTEGER : laneCapacity.value,
))

const summary = computed(() => monthSummary(items.value, month.value))

const monthLabel = computed(() => `${monthNames[month.value.month - 1]} ${month.value.year}`)

// Paging resets the expansions: they are about one crowded week on one screen,
// and carrying them to a different month would silently un-cap it.
const goToMonth = (next: typeof month.value): void => {
	month.value = next
	expanded.value = []
}

const onPrevious = (): void => goToMonth(addMonths(month.value, -1))
const onNext = (): void => goToMonth(addMonths(month.value, 1))
const onToday = (): void => goToMonth(monthOf())

const onExpandWeek = (index: number): void => {
	expanded.value = [...expanded.value, index]
}

const detailType = (item: CalendarItem): 'trip' | 'booking' =>
	item.kind === 'trip' ? 'trip' : 'booking'

const onOpen = (item: CalendarItem): void => openDetail(detailType(item), item.id)

const barHref = (item: CalendarItem): string => detailHref(detailType(item), item.id)

// The draft count is the month's outstanding decision, so it opens one rather
// than being a number you have to go and act on somewhere else.
const onOpenDraft = (): void => {
	const draft = firstDraft(items.value, month.value)
	if (draft !== null) {
		onOpen(draft)
	}
}
</script>

<template>
	<div class="tm-content tm-cal">
		<div class="tm-toolbar">
			<!-- The month names where you are, so it leads; the buttons that change
			     it follow. -->
			<div class="tm-cal-nav">
				<h2 class="tm-toolbar-heading tm-cal-month">
					<span>{{ monthLabel }}</span>
					<!-- Every month name of this locale, stacked underneath and hidden:
					     the heading then sizes to the widest one it can ever hold, so the
					     buttons after it stay put as you page. A fixed width cannot do
					     this — month names differ wildly by locale, and by font. -->
					<span v-for="name in monthNames"
						:key="name"
						class="tm-cal-month-ghost"
						aria-hidden="true">{{ `${name} ${month.year}` }}</span>
				</h2>
				<NcButton variant="secondary"
					:aria-label="t('travelmanager', 'Previous month')"
					@click="onPrevious">
					<template #icon>
						<ChevronLeftIcon :size="20" />
					</template>
				</NcButton>
				<NcButton variant="secondary"
					:aria-label="t('travelmanager', 'Next month')"
					@click="onNext">
					<template #icon>
						<ChevronRightIcon :size="20" />
					</template>
				</NcButton>
				<NcButton variant="secondary" @click="onToday">
					{{ t('travelmanager', 'Today') }}
				</NcButton>
			</div>
			<NcButton :variant="showAll ? 'primary' : 'tertiary'" @click="showAll = !showAll">
				{{ t('travelmanager', 'Show archived & discarded') }}
			</NcButton>
		</div>

		<!-- Scoped to the month on screen, which is the one count the navigation's
		     global counters cannot give you. -->
		<div class="tm-cal-summary">
			<span>{{ t('travelmanager', '{n} trip(s)', { n: summary.trips }) }}</span>
			<span aria-hidden="true">·</span>
			<span>{{ t('travelmanager', '{n} booking(s)', { n: summary.bookings }) }}</span>
			<template v-if="summary.drafts > 0">
				<span aria-hidden="true">·</span>
				<NcButton variant="secondary" @click="onOpenDraft">
					{{ t('travelmanager', '{n} draft(s) to review', { n: summary.drafts }) }}
				</NcButton>
			</template>
			<!-- The grid stays whatever happens — a calendar with no days is not a
			     calendar — so the empty case is explained here instead of replacing
			     the view with an NcEmptyContent as the lists do. -->
			<span v-if="!loading && items.length === 0">
				{{ t('travelmanager', 'Nothing dated yet — bookings appear here once they have travel dates.') }}
			</span>
		</div>

		<div class="tm-cal-weekdays" aria-hidden="true">
			<div v-for="(name, index) in weekdays" :key="index" class="tm-cal-weekday">
				{{ name }}
			</div>
		</div>

		<div ref="grid" class="tm-cal-grid">
			<div v-for="(week, weekIndex) in layouts" :key="week.days[0]" class="tm-cal-week">
				<!-- Backgrounds are their own layer so a day cell can span every lane
				     without the lane count having to be known to CSS. -->
				<div class="tm-cal-week-bg" aria-hidden="true">
					<div v-for="day in week.days"
						:key="day"
						class="tm-cal-day"
						:class="{ 'tm-cal-day-outside': !isInMonth(day, month) }" />
				</div>

				<div class="tm-cal-week-fg">
					<div v-for="(day, column) in week.days"
						:key="day"
						class="tm-cal-daynum"
						:class="{
							'tm-cal-daynum-outside': !isInMonth(day, month),
							'tm-cal-daynum-today': day === today,
						}"
						:style="{ gridColumn: column + 1 }">
						<span>{{ Number(day.slice(8, 10)) }}</span>
					</div>

					<CalendarBar v-for="segment in week.segments"
						:key="segment.item.key"
						:item="segment.item"
						:continues-left="segment.continuesLeft"
						:continues-right="segment.continuesRight"
						:selected="isOpen(detailType(segment.item), segment.item.id)"
						:href="barHref(segment.item)"
						:style="{
							gridColumn: `${segment.colStart + 1} / span ${segment.colSpan}`,
							gridRow: segment.lane + 2,
						}"
						@click.prevent="onOpen(segment.item)" />

					<template v-for="(cut, column) in week.hidden" :key="column">
						<button v-if="cut.length > 0"
							type="button"
							class="tm-cal-more"
							:style="{ gridColumn: column + 1, gridRow: week.lanes + 2 }"
							@click="onExpandWeek(weekIndex)">
							{{ t('travelmanager', '+{n} more', { n: cut.length }) }}
						</button>
					</template>
				</div>
			</div>
		</div>
	</div>
</template>
