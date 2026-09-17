import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

// NB: interpolate the path directly rather than passing it as a {param}, because
// generateOcsUrl runs encodeURIComponent on params (escape defaults to true),
// which would turn the slash in multi-segment paths (e.g. "dev/logs") into
// "%2F" and break route matching. All callers pass app-controlled paths with
// numeric ids, so no escaping is needed here.
const base = (path: string): string => generateOcsUrl(`apps/travelmanager/api/${path}`)

/** A local wall-clock instant + informational place/timezone (no tz conversion, V8). */
export interface WhenWhere {
	local?: string | null
	location?: string | null
	timezone?: string | null
}

export interface Passenger {
	name?: string | null
	frequentFlyer?: string | null
	/** e-ticket number for this passenger. One PNR can hold several. */
	ticketNumber?: string | null
	/**
	 * Not asked for — a seat belongs to a leg, so the schema puts it on the
	 * segment. Read anyway: coach emails print the seat inside the passenger's
	 * own block, and a model that follows the email's layout rather than ours
	 * should not cause the seat to vanish from the card.
	 */
	seat?: string | null
	baggage?: string | null
}

/** Who sits where on one leg. Eurostar states this per passenger per leg. */
export interface SeatAssignment {
	passenger?: string | null
	coach?: string | null
	seat?: string | null
}

/**
 * One timed hop between two places — a flight leg, a train leg, a coach leg.
 *
 * One interface for all three because `details` is loose JSON and the shapes
 * genuinely overlap: everything but the last four fields is common. The
 * type-specific tail is optional on both sides rather than split into two
 * interfaces, which would force `BookingDetails` to intersect two conflicting
 * `segments` array types.
 */
export interface JourneySegment {
	carrier?: string | null
	operatingCarrier?: string | null
	origin?: string | null
	destination?: string | null
	departureLocal?: string | null
	departureTimezone?: string | null
	arrivalLocal?: string | null
	arrivalTimezone?: string | null
	seat?: string | null
	/* Flight */
	flightNumber?: string | null
	cabinClass?: string | null
	terminal?: string | null
	gate?: string | null
	/* Train / bus */
	serviceNumber?: string | null
	fareClass?: string | null
	platform?: string | null
	/**
	 * Seat per passenger on this leg. A seat is a fact about a passenger *and* a
	 * leg, and a rail booking states the whole matrix — two travellers over an
	 * outbound and a return is four different seats. The scalar `seat`/`coach`
	 * above stay readable for older rows and for tickets that name no passenger.
	 */
	seats?: SeatAssignment[]
	coach?: string | null
}

export interface JourneyDetails {
	passengers?: Passenger[]
	segments?: JourneySegment[]
	/** Train/bus only: the agency or site the ticket was bought through. */
	retailer?: string | null
}

export interface CarDetails {
	supplier?: string | null
	rentalCompany?: string | null
	carType?: string | null
	carFeatures?: string[]
	driver?: { name?: string | null }
	pickup?: WhenWhere
	dropoff?: WhenWhere
}

export interface HotelDetails {
	propertyName?: string | null
	address?: string | null
	checkIn?: WhenWhere
	checkOut?: WhenWhere
	roomType?: string | null
	board?: string | null
	numberOfRooms?: number | null
	guests?: { name?: string | null }[]
}

export type BookingDetails = JourneyDetails & CarDetails & HotelDetails & Record<string, unknown>

/** The user's decision about a booking, orthogonal to its provider-side status. */
export type ReviewState = 'draft' | 'confirmed' | 'discarded' | 'archived'

export interface Booking {
	id: number
	tripId: number | null
	type: string
	provider: string | null
	bookingReference: string | null
	confirmationNumber: string | null
	title: string | null
	/** What the provider did: active, cancelled or superseded. */
	status: string
	/** What the user decided: draft, confirmed, discarded or archived. */
	reviewState: ReviewState
	confidence: number | null
	/** RFC Message-ID of the email that created this booking — the trail back to it. */
	sourceMessageId: string | null
	/**
	 * The group of maybe-the-same bookings this one belongs to, or null. A group
	 * rather than a pointer at one other booking: three emails about one booking
	 * is ordinary, and every member has to see every other. See
	 * `possibleDuplicates` in bookings.ts.
	 */
	duplicateGroupId: number | null
	details: BookingDetails
	startDate: string | null
	endDate: string | null
	createdAt: string | null
	updatedAt: string | null
	confirmedAt: string | null
}

/** One row of the ingestion ledger: an email that was read from the mailbox. */
export interface Message {
	id: number
	mailbox: string
	messageId: string
	subject: string | null
	/** Display form of the From header; null on messages ingested before it was captured. */
	sender: string | null
	status: string
	failureKind: string | null
	/** ExtractionIssue reason slugs from the last attempt, e.g. 'repaired_json'. */
	issueReasons: string[]
	/**
	 * Bookings this email is about but did not create, because they already
	 * existed. Possible duplicates are *not* here — those are a booking-to-booking
	 * relation and live on `Booking.possibleDuplicateOf`.
	 */
	relatedBookingIds: number[]
	error: string | null
	/** Raw model output from the last attempt (truncated server-side). */
	lastResponse: string | null
	attempts: number
	/** False once the retained body has been dropped — no re-extraction possible. */
	canRetry: boolean
	sentAt: string | null
	processedAt: string | null
}

/** What a trip is for. Open to growth — see Trip::TYPES server-side. */
export type TripType = 'work' | 'leisure'

export interface Trip {
	id: number
	name: string
	/** null until the user classifies it; never guessed from the bookings. */
	type: TripType | null
	/** '#rrggbb', exactly as CSS and NcColorPicker use it, or null. */
	color: string | null
	startDate: string | null
	endDate: string | null
	notes: string | null
}

const unwrap = <T>(data: { ocs: { data: T } }): T => data.ocs.data

export const listBookings = async (reviewState?: ReviewState): Promise<Booking[]> => {
	const res = await axios.get(base('bookings'), { params: reviewState ? { reviewState } : {} })
	return unwrap(res.data)
}

export const updateBooking = async (id: number, fields: Partial<Pick<Booking, 'title' | 'provider' | 'bookingReference' | 'confirmationNumber'>>): Promise<Booking> => {
	const res = await axios.put(base(`bookings/${id}`), fields)
	return unwrap(res.data)
}

/**
 * Confirm / discard / archive / un-discard. Soft — see deleteBooking to purge.
 * @param id the booking to move
 * @param reviewState the target review state
 */
export const setBookingReviewState = async (id: number, reviewState: ReviewState): Promise<Booking> => {
	const res = await axios.post(base(`bookings/${id}/review`), { reviewState })
	return unwrap(res.data)
}

/**
 * Say one booking is not the same as the others it is grouped with. The rest of
 * the group stays grouped. Not undoable — only re-running the source email
 * brings the flag back.
 * @param id the booking to take out of the group
 */
export const leaveDuplicateGroup = async (id: number): Promise<Booking> => {
	const res = await axios.delete(base(`bookings/${id}/duplicate`))
	return unwrap(res.data)
}

/**
 * Permanent removal, leaving no tombstone.
 * @param id the booking to delete
 */
export const deleteBooking = async (id: number): Promise<void> => {
	await axios.delete(base(`bookings/${id}`))
}

export const assignBookingToTrip = async (id: number, tripId: number | null): Promise<Booking> => {
	const res = await axios.post(base(`bookings/${id}/trip`), { tripId })
	return unwrap(res.data)
}

export const listMessages = async (status?: string): Promise<Message[]> => {
	const res = await axios.get(base('messages'), { params: status ? { status } : {} })
	return unwrap(res.data)
}

/**
 * Find the ingested message with a given RFC Message-ID, or null. Used when a
 * booking's source email is older than the message list's page, so it was never
 * loaded — the list caps at 200 rows.
 * @param messageId the RFC Message-ID to look up
 */
export const findMessageBySourceId = async (messageId: string): Promise<Message | null> => {
	const res = await axios.get(base('messages'), { params: { messageId } })
	return unwrap<Message[]>(res.data)[0] ?? null
}

/**
 * Fetch the retained email body for one message. Kept out of the list response
 * because it is the bulky column — only the row the user opened needs it.
 * @param id the message whose body to read
 */
export const fetchMessageBody = async (id: number): Promise<string | null> => {
	const res = await axios.get(base(`messages/${id}/body`))
	return unwrap<{ id: number, bodyText: string | null }>(res.data).bodyText
}

/**
 * Re-run the extraction for an already-ingested message. Asynchronous: the
 * model answers later, so the row updates on a subsequent reload.
 * @param id the message to re-extract
 */
export const retryMessage = async (id: number): Promise<Message> => {
	const res = await axios.post(base(`messages/${id}/retry`), {})
	return unwrap(res.data)
}

export const listTrips = async (): Promise<Trip[]> => {
	const res = await axios.get(base('trips'))
	return unwrap(res.data)
}

/**
 * Fields a trip's editor can set. Type and colour accept '' to clear them —
 * undefined means "leave alone", which is a different request.
 * @see updateTrip
 */
export interface TripFields {
	name?: string
	notes?: string | null
	type?: TripType | ''
	color?: string | ''
}

export const createTrip = async (name: string, fields: Omit<TripFields, 'name'> = {}): Promise<Trip> => {
	const res = await axios.post(base('trips'), { name, ...fields })
	return unwrap(res.data)
}

export const updateTrip = async (id: number, fields: TripFields): Promise<Trip> => {
	const res = await axios.put(base(`trips/${id}`), fields)
	return unwrap(res.data)
}

export const deleteTrip = async (id: number): Promise<void> => {
	await axios.delete(base(`trips/${id}`))
}

export interface UserSettings {
	enabled: boolean
	imapHost: string
	imapPort: number
	imapSecurity: string
	imapUser: string
	mailbox: string
	intervalMinutes: number
	hasPassword: boolean
	isConfigured: boolean
}

export const saveSettings = async (settings: Partial<UserSettings> & { imapPassword?: string }): Promise<UserSettings> => {
	const res = await axios.put(base('settings'), settings)
	return unwrap(res.data)
}

export const testConnection = async (): Promise<{ ok: boolean, error?: string }> => {
	const res = await axios.post(base('settings/test'), {})
	return unwrap(res.data)
}

/**
 * The model an extraction is currently sent to. Read-only diagnostics, handed
 * to the settings panels as initial state rather than fetched: it is a property
 * of the instance's configuration, not of anything the page can change.
 *
 * Note this describes what the NEXT extraction will use — Task Processing keeps
 * no record of the provider or model a completed task ran on, so it cannot be
 * read back per booking.
 */
export interface LlmProviderInfo {
	taskTypeId: string
	providerId: string
	providerName: string
	model: string | null
	maxTokens: number | null
	expectedRuntime: number
	/** Null for a local provider, an unrecognised one, or a non-admin viewer. */
	endpointUrl: string | null
}

/** Everything the settings panels are handed about extraction's LLM side. */
export interface LlmDiagnostics {
	provider: LlmProviderInfo | null
	/** Whether this viewer may see the endpoint URL (admins only). */
	canSeeEndpoint: boolean
	/** The rules + schema sent ahead of each email. */
	promptTemplate: string
}

export interface AdminSettings {
	enabled: boolean
	rateLimitPerRun: number
	localConcurrency: number
}

export const saveAdminSettings = async (settings: Partial<AdminSettings>): Promise<AdminSettings> => {
	const res = await axios.put(base('admin/settings'), settings)
	return unwrap(res.data)
}

/* -------------------------------------------------- developer / debug tools */

export interface LogEntry {
	id: number
	level: string
	step: string
	message: string
	context: string | null
	createdAt: string | null
}

export const runIngestNow = async (): Promise<{ enqueued: number }> => {
	const res = await axios.post(base('dev/ingest'), {})
	return unwrap(res.data)
}

export const fetchLogs = async (): Promise<LogEntry[]> => {
	const res = await axios.get(base('dev/logs'))
	return unwrap(res.data)
}

export const clearLogs = async (): Promise<void> => {
	await axios.delete(base('dev/logs'))
}

export const wipeData = async (): Promise<void> => {
	await axios.delete(base('dev/data'))
}
