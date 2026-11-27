<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { t } from '@nextcloud/l10n'
import { loading, reload } from './store'

/**
 * Re-read bookings, trips and messages from the server.
 *
 * Only a re-read: it does not check the mailbox. A fetched email becomes a
 * booking only after its model task finishes, so checking now would not show a
 * new booking any sooner (see "Refresh re-reads, and sits in the navigation"
 * in docs/decisions.md).
 *
 * One component in two places: the navigation's header row on a wide window
 * (Mail's spot) and each view's toolbar on a phone, where the navigation is a
 * closed drawer. App.vue and the views pick which one renders.
 *
 * Spins and is disabled while *any* reload is in flight, not only its own: the
 * store has one `loading`, and a second click during an action's reload would
 * only start a reload that supersedes it.
 */
</script>

<template>
	<NcButton class="tm-refresh"
		variant="tertiary-no-background"
		:aria-label="t('travelmanager', 'Refresh')"
		:title="t('travelmanager', 'Refresh')"
		:disabled="loading"
		@click="reload()">
		<template #icon>
			<NcLoadingIcon v-if="loading" :size="20" />
			<RefreshIcon v-else :size="20" />
		</template>
	</NcButton>
</template>
