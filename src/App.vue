<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
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
	findMessageBySourceId,
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
	bookingSpan,
	bookingTypes,
	decodeHtmlEntities,
	draftCount as countDrafts,
	filterBookings,
	linkDialogBookings,
	reviewActions,
	sortBookings,
} from './bookings'
import BookingDetails from './BookingDetails.vue'
import DetailSidebar from './DetailSidebar.vue'
import {
	type DetailType,
	type Route,
	byId,
	detailLabel,
	detailRoute,
	formatRoute,
	matchRoute,
	parseRoute,
} from './detail'
import {
	type TripSort,
	TRIP_COLUMNS,
	canCreateTrip,
	filterTripsByPeriod,
	searchTrips,
	sortTrips,
	suggestedTrips,
	tripRows,
} from './trips'

const bookings = ref<Booking[]>([])
const trips = ref<Trip[]>([])
const messages = ref<Message[]>([])
// What is open, mirrored in location.hash so the detail panel is linkable and
// the browser's Back button walks the trail (see src/detail.ts). Each view keeps
// its own filter/sort state below — previously one `filter` ref did double duty
// as view and status, which made "All bookings" a status and Trips a view.
const route = ref<Route>(parseRoute(window.location.hash))

const view = computed({
	get: () => route.value.view,
	// Switching view closes the panel: a booking id means nothing under Messages.
	set: (value: Route['view']) => navigate({ view: value, detail: null }),
})

/**
 * Go somewhere, pushing a history entry so Back returns here. `fromLabel` rides
 * along in history.state rather than in a ref of our own, so the back button's
 * caption survives the browser's own Back and Forward.
 * @param next where to go
 * @param fromLabel what to call the place being left, if it should be offered
 */
const navigate = (next: Route, fromLabel: string | null = null) => {
	route.value = next
	const hash = formatRoute(next)
	if (hash !== window.location.hash) {
		window.history.pushState({ fromLabel }, '', hash)
	}
}

const backLabel = ref<string | null>(null)

const entitiesFor = (type: DetailType): (Booking | Trip | Message)[] => {
	switch (type) {
	case 'booking':
		return bookings.value
	case 'trip':
		return trips.value
	case 'message':
		return messages.value
	}
}

const onOpenDetail = (type: DetailType, id: number) => {
	// Name the place being left, so the panel can offer a way back to it.
	const current = route.value.detail
	const item = current === null ? null : byId(entitiesFor(current.type), current.id)
	const from = current === null || item === null ? null : detailLabel(current.type, item)
	navigate(detailRoute(type, id), from)
	backLabel.value = from
}

/**
 * Whether this row is the one the sidebar is showing — so the panel is visibly
 * anchored to a row rather than floating free of the list.
 * @param type the kind of entity the row holds
 * @param id the row's id
 */
const isOpen = (type: DetailType, id: number): boolean =>
	route.value.detail?.type === type && route.value.detail.id === id

const closeDetail = () => {
	navigate({ view: route.value.view, detail: null })
	backLabel.value = null
}

// Delegated to the browser so our trail and its history are the same thing —
// popstate then restores both the route and the caption.
const goBack = () => window.history.back()

/**
 * A booking's source email may predate the message list's page (it caps at 200),
 * in which case the trail back would silently vanish. Fetch that one message and
 * fold it into the loaded set.
 */
const ensureSourceMessage = async () => {
	const detail = route.value.detail
	if (detail?.type !== 'booking') {
		return
	}
	const booking = byId(bookings.value, detail.id)
	const sourceId = booking?.sourceMessageId
	if (!sourceId || messages.value.some((m) => m.messageId === sourceId)) {
		return
	}
	try {
		const found = await findMessageBySourceId(sourceId)
		if (found !== null) {
			messages.value = [...messages.value, found]
		}
	} catch (e) {
		// Best-effort: the panel simply does not offer the source link.
	}
}

watch(() => route.value.detail, ensureSourceMessage)

// The browser's Back and Forward move the route, and carry the back button's
// caption with them — history.state survives both, a ref would not.
const onPopState = () => {
	const next = matchRoute(window.location.hash)
	if (next === null) {
		// Not one of ours: put our own address back rather than following it.
		window.history.replaceState(window.history.state, '', formatRoute(route.value))
		return
	}
	route.value = next
	backLabel.value = (window.history.state as { fromLabel?: string | null } | null)?.fromLabel ?? null
}
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
// Confirming a draft: the moment a booking becomes something you have decided to
// keep, which is the natural point to say what trip it belongs to.
const confirmOpen = ref(false)
const confirmTarget = ref<Booking | null>(null)
// One selection across both sections of the dialog: a trip id, 'none', or 'new'
// (create whatever is typed in the search box). Nothing is selected initially —
// a pre-selected suggestion would link silently on a wrong guess.
const confirmChoice = ref<string>('')
const confirmSearch = ref('')
const confirmBusy = ref(false)

// Names by id, so the Trip column can render and sort on the name rather than
// the foreign key. Built once per change instead of scanned per row.
const tripNames = computed(() => Object.fromEntries(
	trips.value.map((trip) => [trip.id, tripLabel(trip)]),
))

const filtered = computed(() => sortBookings(
	filterBookings(bookings.value, bookingFilter.value, bookingType.value),
	bookingSort.value,
	bookingSortDirection.value,
	new Date(),
	tripNames.value,
))

const tripNameFor = (tripId: number | null): string =>
	tripId === null ? '' : (tripNames.value[tripId] ?? '')

const visibleMessages = computed(() => sortMessages(
	filterMessagesByStatus(messages.value, messageFilter.value),
	messageSort.value,
	messageSortDirection.value,
))

// Derived once and shared: the Trips grid filters and sorts these, and the
// confirm dialog reads the same rows so both describe a trip identically.
const allTripRows = computed(() => tripRows(trips.value, bookings.value))

const visibleTrips = computed(() => sortTrips(
	filterTripsByPeriod(allTripRows.value, tripFilter.value),
	tripSort.value,
	tripSortDirection.value,
))

/**
 * A trip's dates and size, for choosing between trips in a dialog.
 * @param tripId the trip to describe
 */
const tripSummary = (tripId: number): string => {
	const row = allTripRows.value.find((r) => r.trip.id === tripId)
	if (row === undefined) {
		return ''
	}
	return [
		formatSpan(row.start, row.end) || null,
		t('travelmanager', '{n} booking(s)', { n: row.bookings.length }),
	].filter(Boolean).join(' · ')
}

// Literal t() calls so the strings are extractable; order and default direction
// come from MESSAGE_COLUMNS so the two cannot drift apart.
const columnLabels: Record<MessageSort, string> = {
	subject: t('travelmanager', 'Subject'),
	sender: t('travelmanager', 'From'),
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
	trip: t('travelmanager', 'Trip'),
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

// The other state axis: a fact about the booking, set from the email.
const bookingStatusLabels: Record<string, string> = {
	active: t('travelmanager', 'Active'),
	cancelled: t('travelmanager', 'Cancelled'),
	superseded: t('travelmanager', 'Superseded'),
}

const bookingStatusLabel = (status: string): string => bookingStatusLabels[status] ?? status

/**
 * One-line description of a booking for the places it appears as a list item
 * rather than a grid row (a trip's contents, the link dialog). 'active' is left
 * out: it is the ordinary case and saying so on every row is noise — only a
 * cancellation or supersession is worth the reader's attention.
 * @param item the booking to describe
 */
const bookingMeta = (item: Booking): string => [
	typeName(item.type),
	reviewStateLabel(item.reviewState),
	item.status === 'active' ? null : bookingStatusLabel(item.status),
	bookingSpan(item) || null,
].filter(Boolean).join(' · ')

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

/**
 * Confirming a *draft* asks which trip it belongs to first; every other review
 * transition applies straight away. Restoring a discarded booking also targets
 * 'confirmed', but it is not a first decision and may already have a trip — hence
 * the reviewState check rather than a target check alone.
 * @param item the booking being acted on
 * @param target the review state the button moves it to
 */
const onReviewAction = (item: Booking, target: ReviewState) => {
	if (target === 'confirmed' && item.reviewState === 'draft') {
		openTripDialog(item)
		return
	}
	onReview(item.id, target)
}

/**
 * Open the trip picker for a booking — as the second half of confirming a draft,
 * or on its own for a confirmed booking that never got grouped.
 * @param item the booking to file
 */
const openTripDialog = (item: Booking) => {
	confirmTarget.value = item
	// A booking that already has a trip opens with it selected — the dialog is
	// then showing you where it is, not asking you to start from nothing. A draft
	// still starts empty: nothing pre-selected, so a wrong guess cannot slip
	// through on a single click.
	confirmChoice.value = item.tripId === null ? '' : String(item.tripId)
	confirmSearch.value = ''
	confirmOpen.value = true
}

// Whether the dialog is also confirming, or only re-filing an already-confirmed
// booking. Drives its wording and whether "No trip" is worth offering.
const confirmIsDraft = computed(() => confirmTarget.value?.reviewState === 'draft')
const confirmHasTrip = computed(() => confirmTarget.value?.tripId != null)

/**
 * Archiving and discarding are never the obvious next thing to do, so they never
 * take the primary slot; confirming and restoring are.
 * @param target the review state the button moves the booking to
 */
const reviewVariant = (target: ReviewState): 'primary' | 'secondary' =>
	target === 'archived' || target === 'discarded' ? 'secondary' : 'primary'

// Trips whose dates line up with the booking's own — almost always the one you
// want. Hidden while searching: once you type you are browsing deliberately, and
// a pinned suggestion above filtered results is just noise.
const confirmSuggestions = computed(() => {
	if (confirmTarget.value === null || confirmSearch.value.trim() !== '') {
		return []
	}
	return suggestedTrips(allTripRows.value, confirmTarget.value)
})

// The full list, minus anything already shown as a suggestion.
const confirmCandidates = computed(() => {
	const suggested = new Set(confirmSuggestions.value.map((row) => row.trip.id))
	return searchTrips(allTripRows.value, confirmSearch.value).filter((row) => !suggested.has(row.trip.id))
})

const confirmCanCreate = computed(() => canCreateTrip(allTripRows.value, confirmSearch.value))

/**
 * Enter in the search box takes the create row when there is one, otherwise the
 * first match — so a name can be typed and committed without reaching for the
 * mouse.
 */
const onConfirmSearchEnter = () => {
	if (confirmCanCreate.value) {
		confirmChoice.value = 'new'
		return
	}
	const first = confirmCandidates.value[0]
	if (first !== undefined) {
		confirmChoice.value = String(first.trip.id)
	}
}

/**
 * Apply the dialog: create the trip if asked, link unless "No trip", and confirm
 * when the booking was still a draft. The link is applied *before* the review
 * change so a failure there leaves the booking a draft — recoverable by pressing
 * Confirm again — rather than confirmed but orphaned.
 */
const submitConfirm = async () => {
	const item = confirmTarget.value
	if (item === null || confirmBusy.value || confirmChoice.value === '') {
		return
	}
	const confirming = confirmIsDraft.value
	confirmBusy.value = true
	try {
		let tripId: number | null = null
		if (confirmChoice.value === 'new') {
			tripId = (await createTrip(confirmSearch.value.trim())).id
		} else if (confirmChoice.value !== 'none') {
			tripId = Number(confirmChoice.value)
		}
		// One call covers linking, moving and unlinking; skipped when the choice
		// is the trip the booking is already on, which is the ordinary outcome of
		// opening the dialog just to look.
		const changed = tripId !== item.tripId
		if (changed) {
			await assignBookingToTrip(item.id, tripId)
		}
		if (confirming) {
			await setBookingReviewState(item.id, 'confirmed')
		}
		if (confirming) {
			showSuccess(reviewLabels.confirmed.done)
		} else if (changed) {
			showSuccess(tripId === null
				? t('travelmanager', 'Booking removed from the trip')
				: t('travelmanager', 'Booking added to the trip'))
		}
		confirmOpen.value = false
		confirmTarget.value = null
		await reload()
	} catch (e) {
		showError(t('travelmanager', 'Could not update the booking'))
	} finally {
		confirmBusy.value = false
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

onMounted(async () => {
	// Stamp the initial entry so history.state is never null on the way back,
	// and normalise a bare or malformed hash to the route we actually rendered.
	window.history.replaceState({ fromLabel: null }, '', formatRoute(route.value))
	window.addEventListener('popstate', onPopState)
	await reload()
	// The route may already name a booking whose source email is not in the page.
	await ensureSourceMessage()
})

onUnmounted(() => window.removeEventListener('popstate', onPopState))
</script>

<template>
	<NcContent app-name="travelmanager">
		<NcAppNavigation>
			<template #list>
				<!-- Three views; what to show within each is a filter, not a
				     navigation choice. Counters show what awaits you, not totals. -->
				<NcAppNavigationItem :name="t('travelmanager', 'Bookings')"
					:active="view === 'bookings'"
					@click.prevent="view = 'bookings'">
					<template #counter>
						{{ draftCount }}
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
						<span :class="$style.headingLabel">{{ column.label }}</span>
						<span :class="$style.sortMarker" aria-hidden="true">
							{{ sortMarker(tripSort, column.key, tripSortDirection) }}
						</span>
					</button>
				</div>
				<ol v-if="visibleTrips.length > 0" :class="$style.rows">
					<li v-for="row in visibleTrips" :key="row.trip.id">
						<details :class="[$style.row, { [$style.rowSelected]: isOpen('trip', row.trip.id) }]">
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
								<button type="button"
									:class="[$style.cellText, $style.openLink]"
									@click.stop.prevent="onOpenDetail('trip', row.trip.id)">
									{{ tripLabel(row.trip) }}
								</button>
								<span :class="$style.cellMeta">{{ formatSpan(row.start, row.end) }}</span>
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
									<!-- Read-only here: linking and unlinking both happen in the
									     Bookings dialog, so there is one place that changes what a
									     trip contains. -->
									<li v-for="item in row.bookings" :key="item.id" :class="$style.tripBooking">
										<div :class="$style.tripBookingInfo">
											<strong>{{ bookingLabel(item) }}</strong>
											<span :class="$style.tripBookingMeta">{{ bookingMeta(item) }}</span>
										</div>
									</li>
								</ul>
								<p v-else :class="$style.tripEmpty">
									{{ t('travelmanager', 'No bookings linked to this trip yet.') }}
								</p>
								<div :class="$style.actions">
									<NcButton variant="primary" @click="openLink(row.trip)">
										{{ t('travelmanager', 'Bookings') }}
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
						<span :class="$style.headingLabel">{{ column.label }}</span>
						<span :class="$style.sortMarker" aria-hidden="true">
							{{ sortMarker(messageSort, column.key, messageSortDirection) }}
						</span>
					</button>
				</div>
				<!-- Dropped entirely when empty, not just left without rows: its own
				     top/bottom rules would otherwise collapse into a stray line. -->
				<ol v-if="visibleMessages.length > 0" :class="$style.rows">
					<li v-for="item in visibleMessages" :key="item.id">
						<details :class="[$style.row, { [$style.rowSelected]: isOpen('message', item.id) }]">
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
								<button type="button"
									:class="[$style.cellText, $style.openLink]"
									@click.stop.prevent="onOpenDetail('message', item.id)">
									{{ item.subject || t('travelmanager', '(no subject)') }}
								</button>
								<span :class="$style.cellText">{{ item.sender }}</span>
								<span :class="$style.cellMeta">{{ formatTimestamp(item.sentAt) }}</span>
								<span :class="$style.cellMeta">{{ formatTimestamp(item.processedAt) }}</span>
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
						<span :class="$style.headingLabel">{{ column.label }}</span>
						<span :class="$style.sortMarker" aria-hidden="true">
							{{ sortMarker(bookingSort, column.key, bookingSortDirection) }}
						</span>
					</button>
				</div>
				<ol v-if="filtered.length > 0" :class="$style.rows">
					<li v-for="item in filtered" :key="item.id">
						<details :class="[$style.row, {
							[$style.mutedCard]: item.reviewState === 'discarded' || item.reviewState === 'archived',
							[$style.rowSelected]: isOpen('booking', item.id),
						}]">
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
								<button type="button"
									:class="[$style.cellText, $style.openLink]"
									@click.stop.prevent="onOpenDetail('booking', item.id)">
									{{ item.title || typeName(item.type) }}
								</button>
								<span :class="$style.cellText">{{ tripNameFor(item.tripId) }}</span>
								<!-- A lozenge, matching the type lozenges on a trip row. -->
								<span :class="[$style.badges, $style.cellStatus]">
									<span :class="$style.badge">{{ typeName(item.type) }}</span>
								</span>
								<span :class="$style.cellText">{{ item.provider }}</span>
								<span :class="$style.cellText">{{ item.bookingReference }}</span>
								<span :class="$style.cellMeta">{{ bookingSpan(item) }}</span>
								<span :class="$style.cellMeta">{{ formatTimestamp(item.createdAt) }}</span>
								<span :class="[$style.badges, $style.cellStatus]">
									<!-- Provider-side status only when it isn't the plain 'active'
									     case; the two axes are orthogonal, so both can show. -->
									<span v-if="item.status !== 'active'" :class="[$style.badge, $style.statusBadge]">{{ bookingStatusLabel(item.status) }}</span>
									<span :class="$style.badge">{{ reviewStateLabel(item.reviewState) }}</span>
								</span>
							</summary>
							<div :class="$style.rowBody">
								<BookingDetails :booking="item" />

								<div :class="$style.actions">
									<!-- Primary while the booking has no trip — filing it is then the
									     one thing still outstanding; secondary once it has one, where
									     the button is a way back in rather than a task. -->
									<NcButton v-if="item.reviewState === 'confirmed'"
										:variant="item.tripId === null ? 'primary' : 'secondary'"
										@click="openTripDialog(item)">
										{{ t('travelmanager', 'Trip') }}
									</NcButton>
									<NcButton v-for="target in reviewActions(item)"
										:key="target"
										:variant="reviewVariant(target)"
										@click="onReviewAction(item, target)">
										{{ actionLabel(item, target) }}
									</NcButton>
									<NcButton v-if="item.reviewState === 'discarded' || item.reviewState === 'archived'"
										variant="error"
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
			@open="onOpenDetail" />

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
						<span :class="$style.tripBookingMeta">{{ bookingMeta(item) }}</span>
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

		<NcDialog v-model:open="confirmOpen"
			:name="confirmIsDraft
				? t('travelmanager', 'Confirm booking')
				: (confirmHasTrip ? t('travelmanager', 'Change trip') : t('travelmanager', 'Add to a trip'))"
			size="normal">
			<div v-if="confirmTarget" :class="$style.confirmHeader">
				<strong>{{ bookingLabel(confirmTarget) }}</strong>
				<span v-if="bookingSpan(confirmTarget)" :class="$style.tripBookingMeta">
					{{ bookingSpan(confirmTarget) }}
				</span>
			</div>

			<!-- Rendered only when it has something in it, so a booking with no
			     dates simply gets the plain searchable list. -->
			<template v-if="confirmSuggestions.length > 0">
				<h3 :class="$style.confirmHeading">
					{{ t('travelmanager', 'Suggested') }}
				</h3>
				<div :class="$style.tripChoices">
					<NcCheckboxRadioSwitch v-for="row in confirmSuggestions"
						:key="row.trip.id"
						v-model="confirmChoice"
						type="radio"
						name="confirm-trip"
						:value="String(row.trip.id)">
						{{ tripLabel(row.trip) }}
						<span :class="$style.tripBookingMeta"> — {{ tripSummary(row.trip.id) }}</span>
					</NcCheckboxRadioSwitch>
				</div>
			</template>

			<h3 :class="$style.confirmHeading">
				{{ t('travelmanager', 'All trips') }}
			</h3>
			<NcTextField v-model="confirmSearch"
				:label="t('travelmanager', 'Search, or type a new trip name')"
				:disabled="confirmBusy"
				@keydown.enter="onConfirmSearchEnter" />
			<!-- One radio group across both sections (same `name`), so choosing in
			     one clears the other. -->
			<div :class="[$style.tripChoices, $style.tripChoicesScroll]">
				<NcCheckboxRadioSwitch v-if="confirmCanCreate"
					v-model="confirmChoice"
					type="radio"
					name="confirm-trip"
					value="new">
					{{ t('travelmanager', 'Create “{name}”', { name: confirmSearch.trim() }) }}
				</NcCheckboxRadioSwitch>
				<!-- Offered when it can actually do something: confirming without a
				     trip, or unlinking one that has one. Omitted for a booking that
				     already has no trip, where it would be a control that does nothing. -->
				<NcCheckboxRadioSwitch v-if="confirmIsDraft || confirmHasTrip"
					v-model="confirmChoice"
					type="radio"
					name="confirm-trip"
					value="none">
					{{ t('travelmanager', 'No trip') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-for="row in confirmCandidates"
					:key="row.trip.id"
					v-model="confirmChoice"
					type="radio"
					name="confirm-trip"
					:value="String(row.trip.id)">
					{{ tripLabel(row.trip) }}
					<span :class="$style.tripBookingMeta"> — {{ tripSummary(row.trip.id) }}</span>
				</NcCheckboxRadioSwitch>
				<p v-if="confirmCandidates.length === 0 && !confirmCanCreate" :class="$style.tripEmpty">
					{{ trips.length === 0
						? t('travelmanager', 'No trips yet — type a name above to create your first.')
						: t('travelmanager', 'No trip matches that search.') }}
				</p>
			</div>
			<template #actions>
				<NcButton variant="tertiary" :disabled="confirmBusy" @click="confirmOpen = false">
					{{ t('travelmanager', 'Cancel') }}
				</NcButton>
				<!-- Disabled until a choice is made: nothing is pre-selected, so an
				     enabled button would have no defined meaning. -->
				<NcButton variant="primary"
					:disabled="confirmBusy || confirmChoice === ''"
					@click="submitConfirm">
					{{ confirmIsDraft
						? t('travelmanager', 'Confirm')
						: (confirmHasTrip ? t('travelmanager', 'Save') : t('travelmanager', 'Add to trip')) }}
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

   Subject and From share the flexible space 3:2 — the subject is the longer
   string and the one you read; the sender only needs to be recognisable. */
.messageColumns {
	grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 180px 180px 80px 200px;
}

/* Title takes the lion's share for the same reason Subject does; Trip and
   Provider stretch too, since trip and supplier names vary far more in length
   than a type, a reference or a date. */
.bookingColumns {
	grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 110px minmax(0, 2fr) 140px 190px 165px 135px;
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
	overflow: hidden;
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

/* The label is its own element because the heading is a flex container, and
   text-overflow does not apply to a bare text node on a flex line — without this
   a long heading spills into the next column instead of truncating. */
.headingLabel {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Never squeezed out: the arrow is the only thing saying which way the column
   is sorted. */
.sortMarker {
	flex-shrink: 0;
	font-size: 0.75em;
	line-height: 1;
}

.rowSummary::-webkit-details-marker {
	display: none;
}

/* The row whose entity the sidebar is showing. Set on the row rather than its
   summary so the expanded body is tinted too, and so the hover rule below still
   wins while you point at it. */
.rowSelected {
	background-color: var(--color-primary-element-light);
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

/* The primary cell doubles as the way into the detail panel. Styled as text
   rather than a button so the row still reads as a row; the underline on hover
   is what says it is clickable. Scoped for the specificity — the server's own
   `button:not(…)` rules outrank a bare class. */
.rowSummary .openLink {
	/* min-height too, not just height: the server gives every button a 44px
	   clickable-area floor, which silently made every grid row that tall. */
	height: auto;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	background-color: transparent;
	font: inherit;
	color: inherit;
	text-align: start;
	cursor: pointer;
}

.rowSummary .openLink:hover,
.rowSummary .openLink:focus {
	background-color: transparent;
	text-decoration: underline;
}

.rowSummary .openLink:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
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
		grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 180px 200px;
	}

	/* Last processed, Attempts. */
	.messageColumns > *:nth-child(5),
	.messageColumns > *:nth-child(6) {
		display: none;
	}

	.bookingColumns {
		grid-template-columns: 16px minmax(0, 3fr) minmax(0, 2fr) 110px minmax(0, 2fr) 190px 135px;
	}

	/* Reference, Added — both are lookups rather than things you scan. */
	.bookingColumns > *:nth-child(6),
	.bookingColumns > *:nth-child(8) {
		display: none;
	}
}

@media (max-width: 800px) {
	.messageColumns {
		grid-template-columns: 16px minmax(0, 1fr) auto;
	}

	/* From, Date received — the subject and the status are what you scan for. */
	.messageColumns > *:nth-child(3),
	.messageColumns > *:nth-child(4) {
		display: none;
	}

	.bookingColumns {
		grid-template-columns: 16px minmax(0, 1fr) 190px 135px;
	}

	/* Trip, Type, Provider — the title usually names the latter two anyway
	   ("AMS → SOU"), and the trip is one click away in the Trips view. */
	.bookingColumns > *:nth-child(3),
	.bookingColumns > *:nth-child(4),
	.bookingColumns > *:nth-child(5) {
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

/* The action button, which must never shrink: as a flex item it otherwise gives
   up width to a long booking title and ends up rendering as "Li…". The title is
   the thing with room to give — .tripBookingInfo carries the min-width: 0 that
   lets it wrap instead. */
.tripBooking > *:last-child {
	flex-shrink: 0;
}

.tripBookingInfo {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.confirmHeader {
	display: flex;
	flex-direction: column;
	margin-bottom: 12px;
}

.confirmHeading {
	font-size: 1em;
	color: var(--color-text-maxcontrast);
	margin: 16px 0 6px;
}

.tripChoices {
	display: flex;
	flex-direction: column;
}

/* Capped so a long trip list scrolls inside the dialog rather than pushing the
   actions off the bottom of the screen. */
.tripChoicesScroll {
	max-height: 240px;
	overflow-y: auto;
	margin-top: 6px;
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
