<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import {
	type Booking,
	type Message,
	type ReviewState,
	type Trip,
	assignBookingToTrip,
	createTrip,
	deleteBooking,
	deleteTrip,
	fetchMessageBody,
	listBookings,
	listMessages,
	listTrips,
	retryMessage,
	setBookingReviewState,
	updateTrip,
} from './api'
import {
	type SortDirection,
	formatSpan,
	formatTimestamp,
	nextSortDirection,
} from './grid'
import {
	type MessageSort,
	MESSAGE_COLUMNS,
	filterMessagesByStatus,
	hasDetails,
	messageDetails,
	messageNotices,
	messageStatusLabel,
	needsAttention,
	retryable,
	sortMessages,
} from './messages'
import {
	type BookingSort,
	BOOKING_COLUMNS,
	bookingHeaderFields,
	bookingSpan,
	bookingTypes,
	carFields,
	decodeHtmlEntities,
	draftCount as countDrafts,
	filterBookings,
	flightSegmentFields,
	hotelFields,
	linkDialogBookings,
	passengerLines,
	reviewActions,
	sortBookings,
} from './bookings'
import {
	type TripSort,
	TRIP_COLUMNS,
	filterTripsByPeriod,
	sortTrips,
	tripRows,
} from './trips'

const bookings = ref<Booking[]>([])
const trips = ref<Trip[]>([])
const messages = ref<Message[]>([])
// The view, and each view's own filter/sort state — previously one `filter` ref
// did double duty as both, which made "All bookings" a status and Trips a view.
const view = ref<'bookings' | 'trips' | 'messages'>('bookings')
const bookingFilter = ref('all')
const bookingType = ref('all')
// 'travel' ascending is the default: what is coming up next, first.
const bookingSort = ref<BookingSort>('travel')
const bookingSortDirection = ref<SortDirection>('asc')
const messageFilter = ref('all')
const messageSort = ref<MessageSort>('received')
const messageSortDirection = ref<SortDirection>('desc')
const tripFilter = ref('all')
const tripSort = ref<TripSort>('travel')
const tripSortDirection = ref<SortDirection>('asc')
const loading = ref(true)
const newTripOpen = ref(false)
const newTripName = ref('')
// When set, the trip dialog edits this trip instead of creating a new one.
const editTripTarget = ref<Trip | null>(null)

// Trip-linking dialog state.
const linkOpen = ref(false)
const linkTarget = ref<Trip | null>(null)
// Trip-deletion confirmation state.
const deleteTripOpen = ref(false)
const deleteTripTarget = ref<Trip | null>(null)
// Permanent booking-deletion confirmation state.
const deleteBookingOpen = ref(false)
const deleteBookingTarget = ref<Booking | null>(null)

const filtered = computed(() => sortBookings(
	filterBookings(bookings.value, bookingFilter.value, bookingType.value),
	bookingSort.value,
	bookingSortDirection.value,
))

const visibleMessages = computed(() => sortMessages(
	filterMessagesByStatus(messages.value, messageFilter.value),
	messageSort.value,
	messageSortDirection.value,
))

const visibleTrips = computed(() => sortTrips(
	filterTripsByPeriod(tripRows(trips.value, bookings.value), tripFilter.value),
	tripSort.value,
	tripSortDirection.value,
))

// Literal t() calls so the strings are extractable; order and default direction
// come from MESSAGE_COLUMNS so the two cannot drift apart.
const columnLabels: Record<MessageSort, string> = {
	sender: t('travelmanager', 'From'),
	subject: t('travelmanager', 'Subject'),
	received: t('travelmanager', 'Date received'),
	processed: t('travelmanager', 'Last processed'),
	attempts: t('travelmanager', 'Attempts'),
	status: t('travelmanager', 'Status'),
}

const messageColumns = MESSAGE_COLUMNS.map((column) => ({
	key: column.key,
	label: columnLabels[column.key],
}))

const bookingColumnLabels: Record<BookingSort, string> = {
	title: t('travelmanager', 'Title'),
	type: t('travelmanager', 'Type'),
	provider: t('travelmanager', 'Provider'),
	reference: t('travelmanager', 'Reference'),
	travel: t('travelmanager', 'Travel dates'),
	added: t('travelmanager', 'Added'),
	reviewState: t('travelmanager', 'Status'),
}

const bookingColumns = BOOKING_COLUMNS.map((column) => ({
	key: column.key,
	label: bookingColumnLabels[column.key],
}))

const tripColumnLabels: Record<TripSort, string> = {
	name: t('travelmanager', 'Trip'),
	travel: t('travelmanager', 'Travel dates'),
	bookings: t('travelmanager', 'Bookings'),
}

const tripColumns = TRIP_COLUMNS.map((column) => ({
	key: column.key,
	label: tripColumnLabels[column.key],
}))

const tripFilters: { key: string, label: string }[] = [
	{ key: 'all', label: t('travelmanager', 'All') },
	{ key: 'current', label: t('travelmanager', 'Current') },
	{ key: 'future', label: t('travelmanager', 'Future') },
	{ key: 'past', label: t('travelmanager', 'Past') },
]

const onSortTripColumn = (column: TripSort): void => {
	tripSortDirection.value = nextSortDirection(TRIP_COLUMNS, column, tripSort.value, tripSortDirection.value)
	tripSort.value = column
}

const onSortColumn = (column: MessageSort): void => {
	messageSortDirection.value = nextSortDirection(MESSAGE_COLUMNS, column, messageSort.value, messageSortDirection.value)
	messageSort.value = column
}

const onSortBookingColumn = (column: BookingSort): void => {
	bookingSortDirection.value = nextSortDirection(BOOKING_COLUMNS, column, bookingSort.value, bookingSortDirection.value)
	bookingSort.value = column
}

/**
 * The ▲/▼ next to the column currently sorted on, empty for the others. Shared
 * by both grids — the two differ only in which refs they pass in.
 * @param active the column the grid is sorted on
 * @param column the column being rendered
 * @param direction the direction currently applied
 */
const sortMarker = (active: string, column: string, direction: SortDirection): string => {
	if (active !== column) {
		return ''
	}
	return direction === 'asc' ? '▲' : '▼'
}

// Used for both the grid's Type column and the type filter chips, so the two
// always read the same.
const typeName = (type: string): string => {
	switch (type) {
	case 'flight':
		return t('travelmanager', 'Flight')
	case 'accommodation':
		return t('travelmanager', 'Accommodation')
	case 'car_rental':
		return t('travelmanager', 'Car rental')
	default:
		return type
	}
}

const reviewStateLabels: Record<string, string> = {
	draft: t('travelmanager', 'Draft'),
	confirmed: t('travelmanager', 'Confirmed'),
	discarded: t('travelmanager', 'Discarded'),
	archived: t('travelmanager', 'Archived'),
}

const reviewStateLabel = (state: string): string => reviewStateLabels[state] ?? state

// Only offer type filters that exist in the data — an empty "Car rental" filter
// is just a dead end.
const availableTypes = computed(() => bookingTypes(bookings.value))

const draftCount = computed(() => countDrafts(bookings.value))

// Filter chips, defined once so the markup stays a loop rather than a wall.
const bookingFilters: { key: string, label: string }[] = [
	{ key: 'all', label: t('travelmanager', 'All') },
	{ key: 'draft', label: t('travelmanager', 'Drafts') },
	{ key: 'confirmed', label: t('travelmanager', 'Confirmed') },
	{ key: 'archived', label: t('travelmanager', 'Archived') },
	{ key: 'discarded', label: t('travelmanager', 'Discarded') },
]

const messageFilters: { key: string, label: string }[] = [
	{ key: 'all', label: t('travelmanager', 'All') },
	{ key: 'attention', label: t('travelmanager', 'Needs attention') },
	{ key: 'processed', label: t('travelmanager', 'Extracted') },
	{ key: 'related', label: t('travelmanager', 'Related') },
	{ key: 'no_booking', label: t('travelmanager', 'No booking') },
	{ key: 'processing', label: t('travelmanager', 'Waiting') },
]

const linkCandidates = computed(() => linkTarget.value ? linkDialogBookings(bookings.value, linkTarget.value.id) : [])

const bookingLabel = (item: Booking): string => decodeHtmlEntities(item.title || item.type)

const tripLabel = (trip: Trip): string => decodeHtmlEntities(trip.name)

const attentionCount = computed(() => needsAttention(messages.value).length)

const reload = async () => {
	loading.value = true
	try {
		[bookings.value, trips.value, messages.value] = await Promise.all([listBookings(), listTrips(), listMessages()])
	} catch (e) {
		showError(t('travelmanager', 'Could not load travel data'))
	} finally {
		loading.value = false
	}
}

const copyText = async (text: string) => {
	try {
		await navigator.clipboard.writeText(text)
		showSuccess(t('travelmanager', 'Copied to clipboard'))
	} catch (e) {
		showError(t('travelmanager', 'Could not copy to clipboard'))
	}
}

// Retained email bodies, keyed by message id and fetched on first expand: the
// list response deliberately omits them, and loading every one up front would
// pull up to 20000 chars per row for text nobody has asked to see.
const rawBodies = ref<Record<number, string>>({})

const rawBody = (id: number): string =>
	rawBodies.value[id] ?? t('travelmanager', 'Loading…')

const onRawMessageToggle = async (id: number, event: Event) => {
	const open = (event.target as HTMLDetailsElement).open
	if (!open || rawBodies.value[id] !== undefined) {
		return
	}
	try {
		const body = await fetchMessageBody(id)
		rawBodies.value[id] = body || t('travelmanager', '(no body retained)')
	} catch (e) {
		// Kept in the box rather than raised as a toast: the failure belongs to
		// this one section, and a toast would leave "Loading…" sitting there.
		rawBodies.value[id] = t('travelmanager', 'Could not load the message body.')
	}
}

const onRetryMessage = async (id: number) => {
	try {
		await retryMessage(id)
		// The model answers asynchronously, so the row will not settle until a
		// later reload — say so rather than implying it is already done.
		showSuccess(t('travelmanager', 'Extraction re-scheduled — refresh in a moment to see the result'))
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not re-run the extraction'))
	}
}

// Button label + success toast per target review state. Keeping them together
// means adding a state is a single edit here plus one in reviewActions().
const reviewLabels: Record<ReviewState, { action: string, done: string }> = {
	draft: { action: t('travelmanager', 'Restore'), done: t('travelmanager', 'Booking restored to drafts') },
	confirmed: { action: t('travelmanager', 'Confirm'), done: t('travelmanager', 'Booking confirmed') },
	discarded: { action: t('travelmanager', 'Discard'), done: t('travelmanager', 'Booking discarded') },
	archived: { action: t('travelmanager', 'Archive'), done: t('travelmanager', 'Booking archived') },
}

// A restore back to 'confirmed' is an undo, not a fresh confirmation.
const actionLabel = (item: Booking, target: ReviewState): string =>
	target === 'confirmed' && item.reviewState !== 'draft'
		? t('travelmanager', 'Restore')
		: reviewLabels[target].action

const onReview = async (id: number, target: ReviewState) => {
	try {
		await setBookingReviewState(id, target)
		showSuccess(reviewLabels[target].done)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not update the booking'))
	}
}

const askDeleteBooking = (item: Booking) => {
	deleteBookingTarget.value = item
	deleteBookingOpen.value = true
}

const confirmDeleteBooking = async () => {
	if (deleteBookingTarget.value === null) {
		return
	}
	try {
		await deleteBooking(deleteBookingTarget.value.id)
		showSuccess(t('travelmanager', 'Booking deleted'))
		deleteBookingOpen.value = false
		deleteBookingTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the booking'))
	}
}

const onNewTrip = () => {
	editTripTarget.value = null
	newTripName.value = ''
	newTripOpen.value = true
}

const onEditTrip = (trip: Trip) => {
	editTripTarget.value = trip
	// Decoded so a leftover entity (see decodeHtmlEntities) gets cleaned up on save.
	newTripName.value = decodeHtmlEntities(trip.name)
	newTripOpen.value = true
}

const submitNewTrip = async () => {
	const name = newTripName.value.trim()
	if (!name) {
		return
	}
	try {
		if (editTripTarget.value !== null) {
			await updateTrip(editTripTarget.value.id, { name })
		} else {
			await createTrip(name)
		}
		newTripOpen.value = false
		newTripName.value = ''
		editTripTarget.value = null
		await reload()
	} catch (e) {
		showError(editTripTarget.value !== null ? t('travelmanager', 'Could not update trip') : t('travelmanager', 'Could not create trip'))
	}
}

const openLink = (trip: Trip) => {
	linkTarget.value = trip
	linkOpen.value = true
}

const onLink = async (bookingId: number) => {
	if (linkTarget.value === null) {
		return
	}
	try {
		await assignBookingToTrip(bookingId, linkTarget.value.id)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not link the booking'))
	}
}

const onUnlink = async (bookingId: number) => {
	try {
		await assignBookingToTrip(bookingId, null)
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not unlink the booking'))
	}
}

const askDeleteTrip = (trip: Trip) => {
	deleteTripTarget.value = trip
	deleteTripOpen.value = true
}

const confirmDeleteTrip = async () => {
	if (deleteTripTarget.value === null) {
		return
	}
	try {
		await deleteTrip(deleteTripTarget.value.id)
		showSuccess(t('travelmanager', 'Trip deleted'))
		deleteTripOpen.value = false
		deleteTripTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not delete the trip'))
	}
}

onMounted(reload)
</script>

<template>
	<NcContent app-name="travelmanager">
		<NcAppNavigation>
			<template #list>
				<!-- Three views; what to show within each is a filter, not a
				     navigation choice. Counters show what awaits you, not totals. -->
				<NcAppNavigationItem :name="t('travelmanager', 'Bookings')"
					:active="view === 'bookings'"
					@click="view = 'bookings'">
					<template #counter>
						{{ draftCount }}
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Trips')"
					:active="view === 'trips'"
					@click="view = 'trips'">
					<template #counter>
						{{ trips.length }}
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('travelmanager', 'Messages')"
					:active="view === 'messages'"
					@click="view = 'messages'">
					<template #counter>
						{{ attentionCount }}
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div v-if="view === 'trips'" :class="$style.content">
				<div :class="$style.tripsToolbar">
					<h2 :class="$style.tripsHeading">
						{{ t('travelmanager', 'Trips') }}
					</h2>
					<NcButton variant="primary" @click="onNewTrip">
						{{ t('travelmanager', 'Create trip') }}
					</NcButton>
				</div>
				<div :class="$style.chips">
					<NcButton v-for="chip in tripFilters"
						:key="chip.key"
						:variant="tripFilter === chip.key ? 'primary' : 'tertiary'"
						@click="tripFilter = chip.key">
						{{ chip.label }}
					</NcButton>
				</div>
				<NcEmptyContent v-if="!loading && visibleTrips.length === 0"
					:name="trips.length === 0 ? t('travelmanager', 'No trips yet') : t('travelmanager', 'Nothing matches this filter')"
					:description="trips.length === 0
						? t('travelmanager', 'Create a trip, then group your bookings under it.')
						: t('travelmanager', 'Try a different filter to see your other trips.')" />
				<div v-if="visibleTrips.length > 0" :class="[$style.rowSummary, $style.tripColumns, $style.gridHeader]">
					<span aria-hidden="true" />
					<button v-for="column in tripColumns"
						:key="column.key"
						type="button"
						:class="[$style.columnHeading, { [$style.columnHeadingActive]: tripSort === column.key }]"
						@click="onSortTripColumn(column.key)">
						{{ column.label }}
						<span :class="$style.sortMarker" aria-hidden="true">
							{{ sortMarker(tripSort, column.key, tripSortDirection) }}
						</span>
					</button>
				</div>
				<ol v-if="visibleTrips.length > 0" :class="$style.rows">
					<li v-for="row in visibleTrips" :key="row.trip.id">
						<details :class="$style.row">
							<summary :class="[$style.rowSummary, $style.tripColumns]">
								<svg :class="$style.chevron"
									viewBox="0 0 24 24"
									width="16"
									height="16"
									aria-hidden="true">
									<path d="M9 5l7 7-7 7"
										fill="none"
										stroke="currentColor"
										stroke-width="2"
										stroke-linecap="round"
										stroke-linejoin="round" />
								</svg>
								<span :class="$style.cellText">{{ tripLabel(row.trip) }}</span>
								<span :class="$style.cellMeta">{{ formatSpan(row.start, row.end) || '—' }}</span>
								<!-- Count plus one lozenge per distinct type: what the trip is
								     made of, without opening it. -->
								<span :class="[$style.badges, $style.cellStatus]">
									<span :class="$style.badge">
										{{ t('travelmanager', '{n} booking(s)', { n: row.bookings.length }) }}
									</span>
									<span v-for="type in row.types" :key="type" :class="$style.badge">
										{{ typeName(type) }}
									</span>
								</span>
							</summary>
							<div :class="$style.rowBody">
								<ul v-if="row.bookings.length > 0" :class="$style.tripBookings">
									<li v-for="item in row.bookings" :key="item.id" :class="$style.tripBooking">
										<div :class="$style.tripBookingInfo">
											<strong>{{ bookingLabel(item) }}</strong>
											<span :class="$style.tripBookingMeta">
												{{ typeName(item.type) }} · {{ reviewStateLabel(item.reviewState) }}
												<template v-if="bookingSpan(item)"> · {{ bookingSpan(item) }}</template>
											</span>
										</div>
										<NcButton variant="tertiary" @click="onUnlink(item.id)">
											{{ t('travelmanager', 'Unlink') }}
										</NcButton>
									</li>
								</ul>
								<p v-else :class="$style.tripEmpty">
									{{ t('travelmanager', 'No bookings linked to this trip yet.') }}
								</p>
								<div :class="$style.actions">
									<NcButton variant="secondary" @click="openLink(row.trip)">
										{{ t('travelmanager', 'Link booking') }}
									</NcButton>
									<NcButton variant="secondary" @click="onEditTrip(row.trip)">
										{{ t('travelmanager', 'Edit trip') }}
									</NcButton>
									<NcButton variant="error" @click="askDeleteTrip(row.trip)">
										{{ t('travelmanager', 'Delete trip') }}
									</NcButton>
								</div>
							</div>
						</details>
					</li>
				</ol>
			</div>
			<div v-else-if="view === 'messages'" :class="$style.content">
				<div :class="$style.tripsToolbar">
					<h2 :class="$style.tripsHeading">
						{{ t('travelmanager', 'Messages') }}
					</h2>
				</div>
				<div :class="$style.chips">
					<NcButton v-for="chip in messageFilters"
						:key="chip.key"
						:variant="messageFilter === chip.key ? 'primary' : 'tertiary'"
						@click="messageFilter = chip.key">
						{{ chip.label }}
					</NcButton>
				</div>
				<NcEmptyContent v-if="!loading && visibleMessages.length === 0"
					:name="messages.length === 0 ? t('travelmanager', 'Nothing ingested yet') : t('travelmanager', 'Nothing matches this filter')"
					:description="messages.length === 0
						? t('travelmanager', 'Emails read from your travel mailbox will be listed here.')
						: t('travelmanager', 'Try a different filter to see the other messages.')" />
				<!-- A grid, not a <table>: each row is a native <details> so the
				     disclosure is keyboard-operable and carries no open/closed state of
				     its own, which a table's row-pair markup cannot do. The headings are
				     therefore plain buttons — table ARIA here would promise a structure
				     the markup does not have. -->
				<div v-if="visibleMessages.length > 0" :class="[$style.rowSummary, $style.messageColumns, $style.gridHeader]">
					<span aria-hidden="true" />
					<button v-for="column in messageColumns"
						:key="column.key"
						type="button"
						:class="[$style.columnHeading, { [$style.columnHeadingActive]: messageSort === column.key }]"
						@click="onSortColumn(column.key)">
						{{ column.label }}
						<span :class="$style.sortMarker" aria-hidden="true">
							{{ sortMarker(messageSort, column.key, messageSortDirection) }}
						</span>
					</button>
				</div>
				<!-- Dropped entirely when empty, not just left without rows: its own
				     top/bottom rules would otherwise collapse into a stray line. -->
				<ol v-if="visibleMessages.length > 0" :class="$style.rows">
					<li v-for="item in visibleMessages" :key="item.id">
						<details :class="$style.row">
							<summary :class="[$style.rowSummary, $style.messageColumns]">
								<svg :class="$style.chevron"
									viewBox="0 0 24 24"
									width="16"
									height="16"
									aria-hidden="true">
									<path d="M9 5l7 7-7 7"
										fill="none"
										stroke="currentColor"
										stroke-width="2"
										stroke-linecap="round"
										stroke-linejoin="round" />
								</svg>
								<span :class="$style.cellText">{{ item.sender || '—' }}</span>
								<span :class="$style.cellText">{{ item.subject || t('travelmanager', '(no subject)') }}</span>
								<span :class="$style.cellMeta">{{ formatTimestamp(item.sentAt) || '—' }}</span>
								<span :class="$style.cellMeta">{{ formatTimestamp(item.processedAt) || '—' }}</span>
								<span :class="$style.cellMeta">{{ item.attempts }}</span>
								<span :class="[$style.badge, $style.cellStatus, { [$style.statusBadge]: item.status === 'failed' || item.status === 'dropped' }]">
									{{ messageStatusLabel(item.status) }}
								</span>
							</summary>
							<div :class="$style.rowBody">
								<!-- No metadata repeated here: the grid row above already carries
								     From/dates/attempts. What the body adds is what does not fit
								     a column — why it failed, and the retry. -->
								<NcNoteCard v-for="(notice, i) in messageNotices(item)"
									:key="i"
									:type="notice.type"
									:text="notice.text"
									:class="$style.notice" />
								<!-- The two halves of a diagnosis: what we sent the model, and
								     what it sent back. Both start collapsed — the body is fetched
								     only once its section is opened. -->
								<details v-if="item.canRetry"
									:class="$style.errorDetails"
									@toggle="onRawMessageToggle(item.id, $event)">
									<summary>{{ t('travelmanager', 'Raw message') }}</summary>
									<div :class="$style.textBox">
										<!-- Wrapper, not the button itself: NcButton's own
										     `position: relative` is the same specificity as ours and
										     its stylesheet loads later, so it would win. -->
										<span :class="$style.copyButton">
											<NcButton variant="secondary" @click="copyText(rawBody(item.id))">
												{{ t('travelmanager', 'Copy') }}
											</NcButton>
										</span>
										<pre :class="$style.errorText">{{ rawBody(item.id) }}</pre>
									</div>
								</details>
								<!-- Long, so kept out of the way — but opened by default when
								     something went wrong, since that is why you came here. -->
								<details v-if="hasDetails(item)"
									:class="$style.errorDetails"
									:open="item.status === 'failed' || item.status === 'dropped' || item.status === 'related'">
									<summary>{{ t('travelmanager', 'Model response') }}</summary>
									<div :class="$style.textBox">
										<span :class="$style.copyButton">
											<NcButton variant="secondary" @click="copyText(messageDetails(item))">
												{{ t('travelmanager', 'Copy') }}
											</NcButton>
										</span>
										<pre :class="$style.errorText">{{ messageDetails(item) }}</pre>
									</div>
								</details>
								<div :class="$style.actions">
									<!-- Shown whenever a retry is possible in principle, disabled
									     while one is in flight: a control that vanishes is more
									     confusing than one that greys out with a reason. -->
									<NcButton v-if="item.canRetry"
										variant="secondary"
										:disabled="!retryable(item)"
										@click="onRetryMessage(item.id)">
										{{ t('travelmanager', 'Retry extraction') }}
									</NcButton>
									<span v-if="!item.canRetry" :class="$style.tripBookingMeta">
										{{ t('travelmanager', 'The email text is no longer retained, so this cannot be re-run.') }}
									</span>
								</div>
							</div>
						</details>
					</li>
				</ol>
			</div>
			<div v-else :class="$style.content">
				<div :class="$style.tripsToolbar">
					<h2 :class="$style.tripsHeading">
						{{ t('travelmanager', 'Bookings') }}
					</h2>
				</div>
				<div :class="$style.chips">
					<NcButton v-for="chip in bookingFilters"
						:key="chip.key"
						:variant="bookingFilter === chip.key ? 'primary' : 'tertiary'"
						@click="bookingFilter = chip.key">
						{{ chip.label }}
					</NcButton>
				</div>
				<div v-if="availableTypes.length > 1" :class="$style.chips">
					<NcButton :variant="bookingType === 'all' ? 'secondary' : 'tertiary'"
						@click="bookingType = 'all'">
						{{ t('travelmanager', 'All types') }}
					</NcButton>
					<NcButton v-for="type in availableTypes"
						:key="type"
						:variant="bookingType === type ? 'secondary' : 'tertiary'"
						@click="bookingType = type">
						{{ typeName(type) }}
					</NcButton>
				</div>
				<NcEmptyContent v-if="!loading && filtered.length === 0"
					:name="bookings.length === 0 ? t('travelmanager', 'Nothing here yet') : t('travelmanager', 'Nothing matches these filters')"
					:description="bookings.length === 0
						? t('travelmanager', 'Travel bookings extracted from your mailbox will appear here as drafts.')
						: t('travelmanager', 'Try a different filter to see your other bookings.')" />
				<!-- Same grid as Messages: shared .rowSummary/.rows/.gridHeader, with
				     only the column template differing (see .bookingColumns). -->
				<div v-if="filtered.length > 0" :class="[$style.rowSummary, $style.bookingColumns, $style.gridHeader]">
					<span aria-hidden="true" />
					<button v-for="column in bookingColumns"
						:key="column.key"
						type="button"
						:class="[$style.columnHeading, { [$style.columnHeadingActive]: bookingSort === column.key }]"
						@click="onSortBookingColumn(column.key)">
						{{ column.label }}
						<span :class="$style.sortMarker" aria-hidden="true">
							{{ sortMarker(bookingSort, column.key, bookingSortDirection) }}
						</span>
					</button>
				</div>
				<ol v-if="filtered.length > 0" :class="$style.rows">
					<li v-for="item in filtered" :key="item.id">
						<details :class="[$style.row, { [$style.mutedCard]: item.reviewState === 'discarded' || item.reviewState === 'archived' }]">
							<summary :class="[$style.rowSummary, $style.bookingColumns]">
								<svg :class="$style.chevron"
									viewBox="0 0 24 24"
									width="16"
									height="16"
									aria-hidden="true">
									<path d="M9 5l7 7-7 7"
										fill="none"
										stroke="currentColor"
										stroke-width="2"
										stroke-linecap="round"
										stroke-linejoin="round" />
								</svg>
								<span :class="$style.cellText">{{ item.title || typeName(item.type) }}</span>
								<span :class="$style.cellText">{{ typeName(item.type) }}</span>
								<span :class="$style.cellText">{{ item.provider || '—' }}</span>
								<span :class="$style.cellText">{{ item.bookingReference || '—' }}</span>
								<span :class="$style.cellMeta">{{ bookingSpan(item) || '—' }}</span>
								<span :class="$style.cellMeta">{{ formatTimestamp(item.createdAt) || '—' }}</span>
								<span :class="[$style.badges, $style.cellStatus]">
									<!-- Provider-side status only when it isn't the plain 'active'
									     case; the two axes are orthogonal, so both can show. -->
									<span v-if="item.status !== 'active'" :class="[$style.badge, $style.statusBadge]">{{ item.status }}</span>
									<span :class="$style.badge">{{ reviewStateLabel(item.reviewState) }}</span>
								</span>
							</summary>
							<div :class="$style.rowBody">
								<!-- Only what is not already a column above. -->
								<div v-if="bookingHeaderFields(item).length > 0" :class="$style.fields">
									<template v-for="field in bookingHeaderFields(item)" :key="field.label">
										<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
										<span :class="$style.fieldValue">{{ field.value }}</span>
									</template>
								</div>

								<!-- Flight: passengers + one row per leg -->
								<template v-if="item.type === 'flight'">
									<div v-if="passengerLines(item.details).length > 0" :class="[$style.fields, $style.typeFields]">
										<span :class="$style.fieldLabel">{{ t('travelmanager', 'Passengers') }}</span>
										<span :class="$style.fieldValue">
											<span v-for="(line, i) in passengerLines(item.details)" :key="i" :class="$style.passenger">
												{{ line }}
											</span>
										</span>
									</div>
									<ul :class="$style.segments">
										<li v-for="(seg, i) in (item.details.segments ?? [])" :key="i" :class="$style.segment">
											<span v-if="(item.details.segments ?? []).length > 1" :class="$style.segmentIndex">
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
								<div v-else-if="item.type === 'car_rental'" :class="[$style.fields, $style.typeFields]">
									<template v-for="field in carFields(item.details)" :key="field.label">
										<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
										<span :class="$style.fieldValue">{{ field.value }}</span>
									</template>
								</div>
								<div v-else-if="item.type === 'accommodation'" :class="[$style.fields, $style.typeFields]">
									<template v-for="field in hotelFields(item.details)" :key="field.label">
										<span :class="$style.fieldLabel">{{ t('travelmanager', field.label) }}</span>
										<span :class="$style.fieldValue">{{ field.value }}</span>
									</template>
								</div>

								<div :class="$style.actions">
									<NcButton v-for="(target, i) in reviewActions(item)"
										:key="target"
										:variant="i === 0 ? 'primary' : 'tertiary'"
										@click="onReview(item.id, target)">
										{{ actionLabel(item, target) }}
									</NcButton>
									<NcButton v-if="item.reviewState === 'discarded' || item.reviewState === 'archived'"
										variant="tertiary"
										@click="askDeleteBooking(item)">
										{{ t('travelmanager', 'Delete permanently') }}
									</NcButton>
								</div>
							</div>
						</details>
					</li>
				</ol>
			</div>
		</NcAppContent>

		<NcDialog v-model:open="newTripOpen"
			:name="editTripTarget ? t('travelmanager', 'Edit trip') : t('travelmanager', 'New trip')"
			size="small">
			<NcTextField v-model="newTripName"
				:label="t('travelmanager', 'Trip name')"
				@keydown.enter="submitNewTrip" />
			<template #actions>
				<NcButton variant="tertiary" @click="newTripOpen = false; editTripTarget = null">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!newTripName.trim()"
					@click="submitNewTrip">
					{{ editTripTarget ? t('travelmanager', 'Save') : t('travelmanager', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="linkOpen"
			:name="t('travelmanager', 'Link a booking')"
			size="normal">
			<p v-if="linkTarget" :class="$style.tripEmpty">
				{{ t('travelmanager', 'Linking to') }} “<strong>{{ tripLabel(linkTarget) }}</strong>”.
			</p>
			<p v-if="linkCandidates.length === 0" :class="$style.tripEmpty">
				{{ t('travelmanager', 'No bookings to show here.') }}
			</p>
			<ul v-else :class="$style.tripBookings">
				<li v-for="item in linkCandidates" :key="item.id" :class="$style.tripBooking">
					<div :class="$style.tripBookingInfo">
						<strong>{{ bookingLabel(item) }}</strong>
						<span :class="$style.tripBookingMeta">{{ item.type }} · {{ item.status }}</span>
					</div>
					<NcButton v-if="item.tripId === linkTarget?.id" variant="tertiary" @click="onUnlink(item.id)">
						{{ t('travelmanager', 'Unlink') }}
					</NcButton>
					<NcButton v-else variant="secondary" @click="onLink(item.id)">
						{{ t('travelmanager', 'Link') }}
					</NcButton>
				</li>
			</ul>
			<template #actions>
				<NcButton variant="primary" @click="linkOpen = false">
					{{ t('travelmanager', 'Done') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="deleteBookingOpen"
			:name="t('travelmanager', 'Delete booking permanently?')"
			size="small">
			{{ t('travelmanager', 'This removes the booking for good. Because no trace is kept, a later email about the same booking will bring it back as a new draft — discarding instead keeps it out of your way permanently. This cannot be undone.') }}
			<template #actions>
				<NcButton variant="tertiary" @click="deleteBookingOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDeleteBooking">
					{{ t('travelmanager', 'Delete permanently') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-model:open="deleteTripOpen"
			:name="t('travelmanager', 'Delete trip?')"
			size="small">
			{{ t('travelmanager', 'This deletes the trip. Its bookings are kept and simply unlinked, so you can re-group them later. This cannot be undone.') }}
			<template #actions>
				<NcButton variant="tertiary" @click="deleteTripOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="confirmDeleteTrip">
					{{ t('travelmanager', 'Delete trip') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcContent>
</template>

<style module>
.content {
	/* Nextcloud floats the app-navigation toggle over the top-left of the content
	   area, so start below it — otherwise it lands on the heading. */
	padding: 44px 16px 16px;
	max-width: none;
}

.badge {
	font-size: 0.8em;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}

.badges {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

/* Amber lozenge: needs the dark warning text colour, not white — matches the
   log level badges in Personal settings. */
.statusBadge {
	color: var(--color-warning-text, #8a6d00);
	background-color: var(--color-warning, #fdf7e6);
	font-weight: bold;
}

/* Discarded/archived rows stay visible but recede. */
.mutedCard {
	opacity: 0.65;
}

/* --- message list ------------------------------------------------------- */

/* Horizontal rules only — no enclosing box. The grid already reads as a unit
   through its column alignment; a border round it just adds a second frame
   inside the app content area. */
.rows {
	list-style: none;
	margin: 0;
	padding: 0;
	border-block: 1px solid var(--color-border);
}

.rows > li + li {
	border-top: 1px solid var(--color-border);
}

/* Shared by both grids (Messages, Bookings); only the column template differs,
   in the .messageColumns / .bookingColumns modifiers below. The heading row
   carries the same pair of classes, which is what keeps headings over columns. */
.rowSummary {
	display: grid;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	cursor: pointer;
	list-style: none;
}

/* Fixed metadata columns, not auto: every row is its own grid container, so
   content-sized columns would land at a different x on each row and the grid
   would read as ragged.

   From and Subject share the flexible space 2:3 — the subject is the longer
   string and the one you read; the sender only needs to be recognisable. */
.messageColumns {
	grid-template-columns: 16px minmax(0, 2fr) minmax(0, 3fr) 180px 180px 80px 200px;
}

/* Title takes the lion's share for the same reason Subject does; Provider gets
   the remaining stretch since supplier names vary far more in length than a
   type, a reference or a date. */
.bookingColumns {
	grid-template-columns: 16px minmax(0, 3fr) 110px minmax(0, 2fr) 140px 190px 165px 135px;
}

/* Only three columns, so the name takes the stretch and the lozenges get a
   generous fixed strip — their number varies with the trip's booking types. */
.tripColumns {
	grid-template-columns: 16px minmax(0, 1fr) 190px 340px;
}

.gridHeader {
	cursor: default;
	padding-block: 0;
	border-bottom: none;
}

/* Headings are buttons, so they need their chrome stripped back to text. Scoped
   under .gridHeader for the specificity: the server's own `button:not(…)` rules
   outrank a bare class and would put a filled pill behind every heading. */
.gridHeader .columnHeading {
	display: flex;
	align-items: center;
	gap: 4px;
	min-width: 0;
	height: auto;
	/* Padded so the hover highlight reads as a target rather than a bare strip,
	   pulled back by the same amount so the label still sits on its column's
	   left edge, over the values below it. */
	margin: 0 -8px;
	padding: 6px 8px;
	border: none;
	border-radius: var(--border-radius);
	background-color: transparent;
	/* `font: inherit` first: a button does not inherit the page font, and 0.85em
	   here made the headings visibly smaller than every other control. */
	font: inherit;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-align: start;
	cursor: pointer;
}

/* :focus and :active are included to defeat the server's own blue button
   states, not for their own sake. */
.gridHeader .columnHeading:hover,
.gridHeader .columnHeading:focus,
.gridHeader .columnHeading:active {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.gridHeader .columnHeading:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.gridHeader .columnHeadingActive {
	color: var(--color-main-text);
}

.sortMarker {
	font-size: 0.75em;
	line-height: 1;
}

.rowSummary::-webkit-details-marker {
	display: none;
}

/* Message rows only: the heading row highlights per column, not as a whole —
   hovering it is aiming at one heading, not selecting the row. */
.rowSummary:not(.gridHeader):hover {
	background-color: var(--color-background-hover);
}

.row summary:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

/* Three cell types, shared by both grids: free text that may overrun its
   column, secondary metadata (dates, counts), and the status badges. */
.cellText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Left-aligned, unlike the old card layout: values now sit under their own
   heading, and a right-aligned column under a left-aligned heading reads as a
   misalignment rather than as a deliberate choice. */
.cellMeta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

/* Left-aligned in a fixed column so the badges start on a common edge. */
.cellStatus {
	justify-self: start;
}

.chevron {
	color: var(--color-text-maxcontrast);
	transition: transform 0.15s ease;
	flex-shrink: 0;
}

.row[open] .chevron {
	transform: rotate(90deg);
}

@media (prefers-reduced-motion: reduce) {
	.chevron {
		transition: none;
	}
}

/* One rhythm inside an expanded row: 14px above the first section, between the
   last section and the actions, and below the actions before the separator. */
.rowBody {
	padding: 14px 14px 14px 42px;
}

/* The first child brings its own top margin, which would stack on the padding. */
.rowBody > *:first-child {
	margin-block-start: 0;
}

/* Scoped: .actions is shared with the Trips and Bookings views, whose spacing
   is not ours to change. */
.rowBody .actions {
	margin-block-start: 14px;
}

/* Columns drop as space runs out, least-scanned first. The expanded row body no
   longer repeats this metadata, so a dropped column is genuinely not shown at
   that width — only Attempts survives, inside the Details block's text.

   The heading row and the message rows have their cells in the same order, so
   one set of nth-child rules hides a column in both — that is the whole reason
   the heading's chevron column is a real (empty) element rather than an offset. */
@media (max-width: 1100px) {
	.messageColumns {
		grid-template-columns: 16px minmax(0, 2fr) minmax(0, 3fr) 180px 200px;
	}

	/* Last processed, Attempts. */
	.messageColumns > *:nth-child(5),
	.messageColumns > *:nth-child(6) {
		display: none;
	}

	.bookingColumns {
		grid-template-columns: 16px minmax(0, 3fr) 110px minmax(0, 2fr) 190px 135px;
	}

	/* Reference, Added — both are lookups rather than things you scan. */
	.bookingColumns > *:nth-child(5),
	.bookingColumns > *:nth-child(7) {
		display: none;
	}
}

@media (max-width: 800px) {
	.messageColumns {
		grid-template-columns: 16px minmax(0, 1fr) auto;
	}

	/* From, Date received — the subject and the status are what you scan for. */
	.messageColumns > *:nth-child(2),
	.messageColumns > *:nth-child(4) {
		display: none;
	}

	.bookingColumns {
		grid-template-columns: 16px minmax(0, 1fr) 190px 135px;
	}

	/* Type, Provider — the title usually names both anyway ("AMS → SOU"). */
	.bookingColumns > *:nth-child(3),
	.bookingColumns > *:nth-child(4) {
		display: none;
	}

	/* The lozenge strip is the widest thing here and the least urgent; the
	   bookings themselves are one click away in the expanded row. */
	.tripColumns {
		grid-template-columns: 16px minmax(0, 1fr) 190px;
	}

	.tripColumns > *:nth-child(4) {
		display: none;
	}
}

@media (max-width: 620px) {
	.content {
		padding-block-start: 16px;
	}

	.rowBody {
		padding-inline-start: 14px;
	}
}

.errorDetails {
	margin: 8px 0 0;
	font-size: 0.9em;
}

/* NcNoteCard ships with a large block margin meant for full-page forms; inside a
   row it only needs to clear its neighbours. Scoped for the specificity — its own
   .notecard rule is a single class and loads after ours. */
.rowBody .notice {
	margin: 8px 0;
}

/* Positioning context for the copy button, which floats over the text rather
   than sitting above it — one less row of chrome between you and the content. */
.textBox {
	position: relative;
}

/* Pinned to the box, not the text, so it stays put while the content scrolls
   under it. Inset clears the pre's own scrollbar. */
.copyButton {
	position: absolute;
	inset-block-start: 12px;
	inset-inline-end: 16px;
	z-index: 1;
}

.errorText {
	margin: 8px 0 0;
	padding: 8px;
	/* Room at the end of the first line so the button never lands on text. */
	padding-inline-end: 72px;
	max-height: 300px;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
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

.actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.tripsToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
	flex-wrap: wrap;
}

.chips {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 12px;
}

.tripsHeading {
	margin: 0;
}

.tripBookings {
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}

.tripBooking {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-top: 1px solid var(--color-border);
}

.tripBookingInfo {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.tripBookingMeta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.tripEmpty {
	color: var(--color-text-maxcontrast);
	margin: 8px 0;
}
</style>
