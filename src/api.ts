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
	baggage?: string | null
}

export interface FlightSegment {
	carrier?: string | null
	operatingCarrier?: string | null
	flightNumber?: string | null
	origin?: string | null
	destination?: string | null
	departureLocal?: string | null
	departureTimezone?: string | null
	arrivalLocal?: string | null
	arrivalTimezone?: string | null
	cabinClass?: string | null
	seat?: string | null
	terminal?: string | null
	gate?: string | null
}

export interface FlightDetails {
	passengers?: Passenger[]
	segments?: FlightSegment[]
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

export type BookingDetails = FlightDetails & CarDetails & HotelDetails & Record<string, unknown>

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
	status: string
	failureKind: string | null
	error: string | null
	/** Raw model output from the last attempt (truncated server-side). */
	lastResponse: string | null
	attempts: number
	/** False once the retained body has been dropped — no re-extraction possible. */
	canRetry: boolean
	sentAt: string | null
	processedAt: string | null
}

export interface Trip {
	id: number
	name: string
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

export const createTrip = async (name: string): Promise<Trip> => {
	const res = await axios.post(base('trips'), { name })
	return unwrap(res.data)
}

export const updateTrip = async (id: number, fields: Partial<Pick<Trip, 'name' | 'notes'>>): Promise<Trip> => {
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
