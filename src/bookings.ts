import type {
	Booking,
	CarDetails,
	FlightDetails,
	FlightSegment,
	HotelDetails,
	WhenWhere,
} from './api'

// Common named entities plus numeric/hex refs (&#39; &#x27; …). Source emails
// occasionally ship a plain-text part that was naively tag-stripped upstream
// without decoding entities, so titles/names extracted from it can carry
// literal "&#39;" etc. through to booking titles and (via copy/paste or
// pre-filled trip names) trip names. Decode defensively wherever such text is
// displayed, so a leftover entity reads as a normal character instead of
// literal markup.
const NAMED_ENTITIES: Record<string, string> = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: '\'',
	nbsp: ' ',
	mdash: '—',
	ndash: '–',
	hellip: '…',
}

const decodeOnce = (value: string): string => {
	return value.replace(/&(#\d+|#x[0-9a-f]+|[a-z]+);/gi, (match, ref: string): string => {
		if (ref[0] === '#') {
			const codePoint = ref[1]?.toLowerCase() === 'x' ? parseInt(ref.slice(2), 16) : parseInt(ref.slice(1), 10)
			return Number.isNaN(codePoint) ? match : String.fromCodePoint(codePoint)
		}
		const decoded = NAMED_ENTITIES[ref.toLowerCase()]
		return decoded ?? match
	})
}

/**
 * Decode common HTML entities in plain text pulled from email content.
 * Some source emails are double-encoded (e.g. "&amp;#39;"): a single pass
 * only turns that into "&#39;", since the newly-formed entity isn't
 * re-scanned within the same replace(). Loop until stable (capped) to fully
 * resolve those.
 * @param value the raw text, possibly containing HTML entities
 */
export const decodeHtmlEntities = (value: string): string => {
	let decoded = value
	for (let i = 0; i < 3; i++) {
		const next = decodeOnce(decoded)
		if (next === decoded) {
			break
		}
		decoded = next
	}
	return decoded
}

/** A single labelled field ready to render as "Label: value". */
export interface SegmentField {
	label: string
	value: string
}

/**
 * Format an ISO-ish local wall-clock string for display.
 * Turns "2026-08-28T17:30:00" into "2026-08-28 17:30"; a date-only midnight
 * ("2026-08-28T00:00:00") renders as just the date. Leaves anything
 * unrecognised untouched. No timezone conversion is performed (V8).
 * @param value the stored local string, or null
 */
export const formatDateTime = (value: string | null | undefined): string => {
	if (!value) {
		return ''
	}
	const match = value.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/)
	if (!match) {
		return value
	}
	return match[2] === '00:00' ? match[1] : `${match[1]} ${match[2]}`
}

// Format a local instant + optional timezone as "2026-08-28 17:30 (Europe/Oslo)".
const formatWhen = (when: WhenWhere | undefined): string => {
	const time = formatDateTime(when?.local)
	if (!time) {
		return ''
	}
	return when?.timezone ? `${time} (${when.timezone})` : time
}

// Push a labelled field only when it carries a value.
const collect = (fields: SegmentField[], label: string, value: string | null | undefined): void => {
	if (value !== null && value !== undefined && value !== '') {
		fields.push({ label, value })
	}
}

/**
 * The booking's cross-type header fields (type, provider, reference numbers),
 * rendered with the same label/value layout as the type-specific detail rows.
 * @param booking the booking to describe
 */
export const bookingHeaderFields = (booking: Booking): SegmentField[] => {
	const fields: SegmentField[] = []
	collect(fields, 'Booking type', booking.type)
	collect(fields, booking.type === 'car_rental' ? 'Supplier' : 'Provider', booking.provider)
	collect(fields, 'Booking reference', booking.bookingReference)
	collect(fields, 'Confirmation number', booking.confirmationNumber)
	return fields
}

/**
 * Labelled fields for one flight leg. Only populated fields are returned so a
 * sparse leg doesn't render empty rows.
 * @param seg the flight segment
 */
export const flightSegmentFields = (seg: FlightSegment): SegmentField[] => {
	const fields: SegmentField[] = []
	const flight = [seg.carrier, seg.flightNumber].filter(Boolean).join(' ')
	collect(fields, 'Flight', flight || null)
	collect(fields, 'Operated by', seg.operatingCarrier)
	collect(fields, 'Origin', seg.origin)
	collect(fields, 'Destination', seg.destination)
	collect(fields, 'Departure', formatWhen({ local: seg.departureLocal, timezone: seg.departureTimezone }))
	collect(fields, 'Arrival', formatWhen({ local: seg.arrivalLocal, timezone: seg.arrivalTimezone }))
	collect(fields, 'Cabin', seg.cabinClass)
	collect(fields, 'Seat', seg.seat)
	collect(fields, 'Terminal', seg.terminal)
	collect(fields, 'Gate', seg.gate)
	return fields
}

/**
 * Labelled fields describing a car rental (supplier, car, pickup/dropoff, driver).
 * @param details the car-rental details
 */
export const carFields = (details: CarDetails): SegmentField[] => {
	const fields: SegmentField[] = []
	collect(fields, 'Supplier', details.supplier)
	collect(fields, 'Rental company', details.rentalCompany)
	collect(fields, 'Car type', details.carType)
	collect(fields, 'Features', (details.carFeatures ?? []).join(', ') || null)
	collect(fields, 'Driver', details.driver?.name)
	collect(fields, 'Pick-up', details.pickup?.location)
	collect(fields, 'Pick-up time', formatWhen(details.pickup))
	collect(fields, 'Drop-off', details.dropoff?.location)
	collect(fields, 'Drop-off time', formatWhen(details.dropoff))
	return fields
}

/**
 * Labelled fields describing an accommodation stay.
 * @param details the accommodation details
 */
export const hotelFields = (details: HotelDetails): SegmentField[] => {
	const fields: SegmentField[] = []
	collect(fields, 'Property', details.propertyName)
	collect(fields, 'Address', details.address)
	collect(fields, 'Check-in', formatWhen(details.checkIn))
	collect(fields, 'Check-out', formatWhen(details.checkOut))
	collect(fields, 'Room', details.roomType)
	collect(fields, 'Board', details.board)
	collect(fields, 'Rooms', details.numberOfRooms != null ? String(details.numberOfRooms) : null)
	const guests = (details.guests ?? []).map((g) => g.name).filter(Boolean).join(', ')
	collect(fields, 'Guests', guests || null)
	return fields
}

/**
 * Passenger summary lines for a flight (name + frequent flyer + baggage).
 * @param details the flight details
 */
export const passengerLines = (details: FlightDetails): string[] =>
	(details.passengers ?? [])
		.map((p) => {
			const extras = [
				p.frequentFlyer ? `FF ${p.frequentFlyer}` : null,
				p.baggage ? `bag ${p.baggage}` : null,
			].filter(Boolean).join(', ')
			const name = p.name ?? ''
			return extras ? `${name} (${extras})` : name
		})
		.filter((line) => line.trim() !== '')

/**
 * Filter bookings by status. The sentinel 'all' returns everything.
 * Pure and free of Nextcloud imports so it is unit-testable in isolation.
 * @param items the bookings to filter
 * @param status the status to keep, or 'all' for no filtering
 */
export const filterByStatus = (items: Booking[], status: string): Booking[] =>
	status === 'all' ? items : items.filter((item) => item.status === status)

/**
 * Number of bookings still awaiting confirmation.
 * @param items the bookings to count drafts in
 */
export const draftCount = (items: Booking[]): number =>
	items.filter((item) => item.status === 'draft').length

/**
 * The bookings linked to a given trip.
 * @param items the bookings to filter
 * @param tripId the trip to collect members for
 */
export const bookingsForTrip = (items: Booking[], tripId: number): Booking[] =>
	items.filter((item) => item.tripId === tripId)

/**
 * The bookings not yet linked to any trip — the pool eligible to be linked.
 * @param items the bookings to filter
 */
export const unassignedBookings = (items: Booking[]): Booking[] =>
	items.filter((item) => item.tripId === null)

/**
 * The bookings shown in the "Link a booking" dialog for a given trip: those
 * already linked to it (so they stay visible with an Unlink action instead of
 * disappearing once linked) plus the still-unassigned pool.
 * @param items the bookings to filter
 * @param tripId the trip being linked to
 */
export const linkDialogBookings = (items: Booking[], tripId: number): Booking[] =>
	items.filter((item) => item.tripId === null || item.tripId === tripId)
