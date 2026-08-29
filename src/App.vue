<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import { t } from '@nextcloud/l10n'
import AppDialogs from './AppDialogs.vue'
import BookingsView from './BookingsView.vue'
import DetailSidebar from './DetailSidebar.vue'
import MessagesView from './MessagesView.vue'
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

/**
 * The shell: navigation, which view is showing, and the detail panel.
 *
 * Everything else lives elsewhere on purpose — the views own their own filters,
 * `store.ts` owns the data, `navigation.ts` owns what is open, `AppDialogs.vue`
 * owns the dialogs (raised from the panel and from a toolbar, so no view owns
 * them), and `grid.css` owns the look the three grids share. Adding a fourth view
 * (the calendar) should mean a new SFC and one nav item here, nothing more.
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
				<!-- Three views; what to show within each is a filter, not a
				     navigation choice. Counters show what awaits you, not totals.
				     `@click.prevent` because NcAppNavigationItem renders <a href="#">,
				     whose stray hash would otherwise bounce the route back. -->
				<NcAppNavigationItem :name="t('travelmanager', 'Bookings')"
					:active="view === 'bookings'"
					@click.prevent="view = 'bookings'">
					<template #counter>
						{{ draftBookingCount }}
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Trips')"
					:active="view === 'trips'"
					@click.prevent="view = 'trips'">
					<template #counter>
						{{ trips.length }}
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Messages')"
					:active="view === 'messages'"
					@click.prevent="view = 'messages'">
					<template #counter>
						{{ attentionCount }}
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<TripsView v-if="view === 'trips'" />
			<MessagesView v-else-if="view === 'messages'" />
			<BookingsView v-else />
		</NcAppContent>

		<!-- One panel for every kind of thing, openable from any view and later
		     from the calendar. Keyed so switching target rebuilds it rather than
		     leaving the previous entity's scroll position behind. -->
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
