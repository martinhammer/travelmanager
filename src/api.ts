import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const base = (path: string): string => generateOcsUrl('apps/travelmanager/api/{path}', { path })

export interface Segment {
	id: number
	bookingId: number
	sequence: number
	startLocal: string | null
	startTimezone: string | null
	endLocal: string | null
	endTimezone: string | null
	origin: string | null
	destination: string | null
	location: string | null
	flightNumber: string | null
	carrier: string | null
	seat: string | null
	terminal: string | null
	gate: string | null
	confidence: number | null
}

export interface Booking {
	id: number
	tripId: number | null
	type: string
	provider: string | null
	bookingReference: string | null
	title: string | null
	status: string
	confidence: number | null
	createdAt: string | null
	updatedAt: string | null
	confirmedAt: string | null
}

export interface BookingWithSegments {
	booking: Booking
	segments: Segment[]
}

export interface Trip {
	id: number
	name: string
	startDate: string | null
	endDate: string | null
	notes: string | null
}

const unwrap = <T>(data: { ocs: { data: T } }): T => data.ocs.data

export const listBookings = async (status?: string): Promise<BookingWithSegments[]> => {
	const res = await axios.get(base('bookings'), { params: status ? { status } : {} })
	return unwrap(res.data)
}

export const updateBooking = async (id: number, fields: Partial<Pick<Booking, 'title' | 'provider' | 'bookingReference'>>): Promise<BookingWithSegments> => {
	const res = await axios.put(base(`bookings/${id}`), fields)
	return unwrap(res.data)
}

export const confirmBooking = async (id: number): Promise<BookingWithSegments> => {
	const res = await axios.post(base(`bookings/${id}/confirm`), {})
	return unwrap(res.data)
}

export const discardBooking = async (id: number): Promise<void> => {
	await axios.delete(base(`bookings/${id}`))
}

export const assignBookingToTrip = async (id: number, tripId: number | null): Promise<BookingWithSegments> => {
	const res = await axios.post(base(`bookings/${id}/trip`), { tripId })
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
