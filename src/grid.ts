/**
 * Shared behaviour for the sortable grids (Messages, Bookings).
 *
 * Kept apart from either view's own module so neither has to import the other,
 * and free of @nextcloud/* imports so it stays unit-testable standalone (§7 of
 * CLAUDE.md). Column *labels* deliberately live in the component, where `t()`
 * can see them as literal strings.
 */

/** Which way a column is ordered. */
export type SortDirection = 'asc' | 'desc'

/** A sortable column: its key, and the direction its first click applies. */
export interface SortColumn<K extends string> {
	key: K
	defaultDirection: SortDirection
}

/**
 * The direction a column should take when the user clicks its heading: its own
 * default on first click, then flipped on every click after that.
 *
 * Defaults differ by column type — dates and counts open descending (newest,
 * most attempts: what you came to look at), text ascending (A→Z, which is what
 * "sort by name" means to everyone).
 * @param columns the grid's column definitions
 * @param column the heading that was clicked
 * @param current the column currently sorted on
 * @param direction the direction currently applied
 */
export const nextSortDirection = <K extends string>(
	columns: SortColumn<K>[],
	column: K,
	current: K,
	direction: SortDirection,
): SortDirection => {
	if (column !== current) {
		return columns.find((c) => c.key === column)?.defaultDirection ?? 'desc'
	}
	return direction === 'asc' ? 'desc' : 'asc'
}

/**
 * Format a travel span for a grid column: the start date, plus the end date when
 * it differs. Dates only — the time of day belongs in the expanded row, and a
 * column has to stay scannable.
 *
 * No timezone conversion (V8): these are local wall-clock at the destination,
 * which is why they are sliced as strings rather than parsed as instants.
 * @param start the start of the span, or null
 * @param end the end of the span, or null
 */
export const formatSpan = (start: string | null, end: string | null): string => {
	const from = (start ?? '').slice(0, 10)
	if (!from) {
		return ''
	}
	const to = (end ?? '').slice(0, 10)
	return to && to !== from ? `${from} → ${to}` : from
}

/**
 * Format an ATOM timestamp for display in the viewer's own locale/timezone.
 * Unlike booking *travel* times (local wall-clock at the destination, never
 * converted — see V8), these are real instants, so converting is correct.
 * @param value the ATOM timestamp, or null
 */
export const formatTimestamp = (value: string | null): string => {
	if (!value) {
		return ''
	}
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return value
	}
	return date.toLocaleString()
}
