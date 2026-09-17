<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import { t } from '@nextcloud/l10n'
import AppDialogs from './AppDialogs.vue'
import BookingsView from './BookingsView.vue'
import CalendarView from './CalendarView.vue'
import DetailSidebar from './DetailSidebar.vue'
import MessagesView from './MessagesView.vue'
import NavCounter from './NavCounter.vue'
import TripsView from './TripsView.vue'
import {
	backLabel,
	closeDetail,
	ensureSourceMessage,
	goBack,
	openLinked,
	route,
	startNavigation,
	view,
} from './navigation'
import { attentionCount, bookings, draftBookingCount, messages, reload, trips } from './store'
import './grid.css'
import './calendar.css'

/**
 * The shell: navigation, which view is showing, and the detail panel.
 *
 * Everything else lives elsewhere on purpose — the views own their own filters,
 * `store.ts` owns the data, `navigation.ts` owns what is open, `AppDialogs.vue`
 * owns the dialogs (raised from the panel and from a toolbar, so no view owns
 * them), `grid.css` owns the look the three list grids share and `calendar.css`
 * the month grid's. Adding the calendar cost exactly what it was meant to: one
 * SFC and one nav item here.
 */

let stopNavigation = (): void => {}

onMounted(async () => {
	stopNavigation = startNavigation()
	await reload()
	// The route may already name a booking whose source email is not in the page.
	await ensureSourceMessage()
})

onUnmounted(() => stopNavigation())
</script>

<template>
	<NcContent app-name="travelmanager">
		<NcAppNavigation>
			<template #list>
				<!-- Four views; what to show within each is a filter, not a
				     navigation choice. A counter is "what awaits you, over how much
				     there is": the lozenge is the outstanding work and the muted
				     number the total, so the lozenge means one thing everywhere and
				     is absent when there is nothing to do. Trips shows a total alone
				     — travel you have filed carries no task, and inventing a queue
				     for the sake of symmetry would make the lozenge mean two
				     different things. The calendar has no counter at all, because
				     its own summary line answers this for the month on screen, which
				     a global count cannot.
				     `@click.prevent` because NcAppNavigationItem renders <a href="#">,
				     whose stray hash would otherwise bounce the route back. -->
				<NcAppNavigationItem :name="t('travelmanager', 'Calendar')"
					:active="view === 'calendar'"
					@click.prevent="view = 'calendar'" />
				<NcAppNavigationItem :name="t('travelmanager', 'Bookings')"
					:active="view === 'bookings'"
					@click.prevent="view = 'bookings'">
					<template #counter>
						<NavCounter :attention="draftBookingCount"
							:total="bookings.length"
							:label="t('travelmanager', '{n} of {total} bookings await review',
								{ n: draftBookingCount, total: bookings.length })" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Trips')"
					:active="view === 'trips'"
					@click.prevent="view = 'trips'">
					<template #counter>
						<NavCounter :total="trips.length"
							:label="t('travelmanager', '{total} trip(s)', { total: trips.length })" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Messages')"
					:active="view === 'messages'"
					@click.prevent="view = 'messages'">
					<template #counter>
						<NavCounter :attention="attentionCount"
							:total="messages.length"
							:label="t('travelmanager', '{n} of {total} messages need attention',
								{ n: attentionCount, total: messages.length })" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<TripsView v-if="view === 'trips'" />
			<MessagesView v-else-if="view === 'messages'" />
			<BookingsView v-else-if="view === 'bookings'" />
			<CalendarView v-else />
		</NcAppContent>

		<!-- One panel for every kind of thing, openable from any view including the
		     calendar. Keyed so switching target rebuilds it rather than leaving the
		     previous entity's scroll position behind. -->
		<DetailSidebar v-if="route.detail !== null"
			:id="route.detail.id"
			:key="`${route.detail.type}-${route.detail.id}`"
			:type="route.detail.type"
			:bookings="bookings"
			:trips="trips"
			:messages="messages"
			:back-label="backLabel"
			@close="closeDetail"
			@back="goBack"
			@open="openLinked" />

		<AppDialogs />
	</NcContent>
</template>
