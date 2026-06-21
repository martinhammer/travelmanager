import type { BookingWithSegments } from './api'

/**
 * Filter bookings by status. The sentinel 'all' returns everything.
 * Pure and free of Nextcloud imports so it is unit-testable in isolation.
 * @param items the bookings to filter
 * @param status the status to keep, or 'all' for no filtering
 */
export const filterByStatus = (
	items: BookingWithSegments[],
	status: string,
): BookingWithSegments[] =>
	status === 'all' ? items : items.filter((item) => item.booking.status === status)

/**
 * Number of bookings still awaiting confirmation.
 * @param items the bookings to count drafts in
 */
export const draftCount = (items: BookingWithSegments[]): number =>
	items.filter((item) => item.booking.status === 'draft').length
