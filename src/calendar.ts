import type { Booking, FlightSegment, ReviewState } from './api'
import type { TripRow } from './trips'
import { localDate } from './grid'

/**
 * The month grid: which days it holds, and how spanning items are laid out on it.
 *
 * Pure and free of @nextcloud/* imports so it stays unit-testable standalone
 * (§7 of CLAUDE.md) — the view supplies locale-dependent inputs (the first day of
 * the week, weekday names) and the already-worded labels, and gets back plain
 * numbers it can turn into `grid-column` / `grid-row`.
 *
 * Dates are wall-clock `YYYY-MM-DD` strings throughout, never instants. Travel
 * times carry no offset (V8), so a booking is on the day its string says it is,
 * in every timezone. All arithmetic anchors to UTC midnight purely so adding a
 * day cannot land on 23:00 the same day across a DST boundary — the result is
 * still read back as a plain date string.
 */

/** A calendar month, 1-based so `{ year: 2026, month: 9 }` reads as September. */
export interface CalendarMonth {
	year: number
	month: number
}

/** Anything that occupies a run of days on the grid. Both bounds inclusive. */
export interface Spanning {
	start: string
	end: string
}

/** How many day columns a week has. Named because it is also a lane's width. */
export const DAYS_IN_WEEK = 7

/**
 * Lanes to allow when the grid has not been measured yet — the very first render,
 * before the ResizeObserver has reported. See lanesForHeight, which supersedes it
 * immediately afterwards.
 *
 * A soft cap either way: a week needing exactly one lane more is allowed to keep
 * it, since "+1 more" occupies the same row it would have saved. See layoutWeek.
 */
export const DEFAULT_MAX_LANES = 5

/**
 * Lanes always shown, however little room the week is nominally given.
 *
 * Not a fallback for cramped windows but a deliberate floor: a week row may grow
 * past its share (`minmax(min-content, 1fr)`) and the grid scrolls, so hiding a
 * booking buys nothing the layout was not willing to give. Six is the point past
 * which a single day stops being readable at a glance and "+N more" is the better
 * answer.
 */
export const MIN_LANES = 6

/**
 * The lane geometry, read back out of calendar.css so the arithmetic here and the
 * pixels there cannot disagree. All in CSS px.
 */
export interface LaneMetrics {
	/** The day-number row, above the lanes. */
	head: number
	/** Padding below the last lane. */
	foot: number
	/** One bar. */
	bar: number
	/** Between two bars. */
	gap: number
}

/**
 * How many lanes fit a week of the given height.
 *
 * **Why this is measured rather than a constant.** A week row is
 * `minmax(min-content, 1fr)`, so its height depends on the window and on how many
 * weeks the month has — anywhere from ~110px to ~250px. A fixed cap therefore
 * says "+3 more" over visible empty space on a tall screen, and overflows a short
 * one. The count only means anything relative to the room there actually is.
 *
 * Fed the *equal share* (grid height ÷ weeks) rather than a measured row, which
 * would be circular: the cap decides the content, the content decides the row.
 * The share raises the count above the floor; it never lowers it — see MIN_LANES.
 * @param available height one week can count on, in px
 * @param metrics the lane geometry from calendar.css
 * @param min lanes to show however little room there is
 */
export const lanesForHeight = (
	available: number,
	metrics: LaneMetrics,
	min: number = MIN_LANES,
): number => {
	const pitch = metrics.bar + metrics.gap
	if (pitch <= 0) {
		return min
	}
	return Math.max(min, Math.floor((available - metrics.head - metrics.foot) / pitch))
}

const utcOf = (day: string): Date => new Date(`${day.slice(0, 10)}T00:00:00Z`)

const dayOf = (date: Date): string => date.toISOString().slice(0, 10)

/**
 * Shift a wall-clock day by whole days.
 * @param day the day to shift, YYYY-MM-DD
 * @param delta days to add; may be negative
 */
export const addDays = (day: string, delta: number): string => {
	const date = utcOf(day)
	date.setUTCDate(date.getUTCDate() + delta)
	return dayOf(date)
}

/**
 * The month a date falls in, by the viewer's own calendar (see localDate — never
 * toISOString, which is the UTC date and is the wrong month either side of a
 * month boundary for most of the world).
 * @param now the reference point; injectable so tests do not depend on today
 */
export const monthOf = (now: Date = new Date()): CalendarMonth => {
	const [year, month] = localDate(now).split('-')
	return { year: Number(year), month: Number(month) }
}

/**
 * Step forward or back by whole months, rolling the year over.
 * @param month the month to move from
 * @param delta months to add; may be negative
 */
export const addMonths = (month: CalendarMonth, delta: number): CalendarMonth => {
	// Zero-based internally so the modulo works, then back to 1-based.
	const total = month.year * 12 + (month.month - 1) + delta
	return { year: Math.floor(total / 12), month: (total % 12) + 1 }
}

/**
 * True when the two name the same month.
 * @param a one month
 * @param b the other
 */
export const sameMonth = (a: CalendarMonth, b: CalendarMonth): boolean =>
	a.year === b.year && a.month === b.month

/**
 * The first and last day of a month, inclusive.
 * @param month the month to bound
 */
export const monthRange = (month: CalendarMonth): { from: string, to: string } => {
	const pad = (value: number): string => String(value).padStart(2, '0')
	const from = `${month.year}-${pad(month.month)}-01`
	// Day 0 of the next month is the last day of this one, leap years included.
	const to = dayOf(new Date(Date.UTC(month.year, month.month, 0)))
	return { from, to }
}

/**
 * The grid's weeks: whole weeks of `YYYY-MM-DD`, padded out of the neighbouring
 * months so every row has seven days.
 *
 * Rows vary between five and six by month rather than being fixed at six. A
 * fixed height would keep the grid from resizing as you page through, but at the
 * cost of a trailing week of somebody else's month on most screens.
 * @param month the month to lay out
 * @param firstDay the locale's first weekday, 0 = Sunday (see getFirstDay)
 */
export const monthWeeks = (month: CalendarMonth, firstDay: number): string[][] => {
	const { from, to } = monthRange(month)
	// How far back the grid starts, so the 1st sits under its own weekday.
	const lead = (utcOf(from).getUTCDay() - firstDay + DAYS_IN_WEEK) % DAYS_IN_WEEK
	const start = addDays(from, -lead)

	const weeks: string[][] = []
	let day = start
	// Whole weeks until the month is covered; the condition is on the week's
	// first day so a month ending mid-week still gets its final row.
	while (day <= to) {
		const week: string[] = []
		for (let i = 0; i < DAYS_IN_WEEK; i++) {
			week.push(day)
			day = addDays(day, 1)
		}
		weeks.push(week)
	}
	return weeks
}

/**
 * Weekday headings rotated into the locale's order.
 * @param names the seven names Sunday-first, as getDayNamesMin returns them
 * @param firstDay the locale's first weekday, 0 = Sunday
 */
export const weekdayLabels = (names: string[], firstDay: number): string[] =>
	Array.from({ length: DAYS_IN_WEEK }, (_, i) => names[(i + firstDay) % DAYS_IN_WEEK] ?? '')

/**
 * Whether a day belongs to the month being shown, rather than to the padding
 * drawn from its neighbours.
 * @param day the day to test
 * @param month the month being shown
 */
export const isInMonth = (day: string, month: CalendarMonth): boolean => {
	const { from, to } = monthRange(month)
	return day >= from && day <= to
}

/**
 * Whether a span touches a closed range of days at all.
 * @param item the span to test
 * @param from first day of the range, inclusive
 * @param to last day of the range, inclusive
 */
export const overlaps = (item: Spanning, from: string, to: string): boolean =>
	item.start <= to && item.end >= from

// --- colour ----------------------------------------------------------------

// '#rrggbb' to channels, or null when it is not a colour we wrote.
const channels = (hex: string): [number, number, number] | null => {
	const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex.trim())
	if (match === null) {
		return null
	}
	return [parseInt(match[1]!, 16), parseInt(match[2]!, 16), parseInt(match[3]!, 16)]
}

// Perceived brightness, 0-1. The sRGB coefficients, not a plain mean: the eye
// reads green as far brighter than blue at the same value.
const luminance = (rgb: [number, number, number]): number =>
	(0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255

/**
 * Text that can be read on a given background.
 *
 * The type palette in calendar.css is picked to carry white at ~5:1, but a trip
 * colour comes from Nextcloud's picker, which offers pale yellows and greens
 * among others — white on those is unreadable. So the text follows the fill
 * rather than being assumed.
 * @param hex the background, '#rrggbb'
 */
export const contrastingText = (hex: string): string => {
	const rgb = channels(hex)
	// Unparseable: keep white, which is what the built-in palette expects.
	return rgb !== null && luminance(rgb) > 0.55 ? '#1a1a1a' : '#ffffff'
}

// --- what goes on the grid -------------------------------------------------

/**
 * One bar on the calendar. Flattened out of Booking/TripRow deliberately: the
 * layout only ever needs a span, and the wording is decided by the caller (this
 * module cannot reach labels.ts without pulling in @nextcloud/l10n).
 */
export interface CalendarItem extends Spanning {
	kind: 'trip' | 'booking'
	/**
	 * The entity this bar opens — **not** unique: a multi-leg flight has one id
	 * across several bars. Use `key` to identify a bar.
	 */
	id: number
	/** Unique per bar, so Vue can key a list in which one booking appears twice. */
	key: string
	/** Booking type slug ('flight', …); null for trips, which have no type. */
	type: string | null
	/** The user's decision, driving the draft/confirmed cue; null for trips. */
	reviewState: ReviewState | null
	/**
	 * The trip colour this bar takes — its own for a trip, its trip's for a
	 * booking. Null when there is no trip, or the trip has no colour, in which
	 * case the bar falls back to the booking-type palette.
	 */
	color: string | null
	label: string
}

/**
 * How a leg is named on its own bar: the flight number and the route it flies.
 *
 * Deliberately terser than `flightSegmentFields`' "Flight" row — a bar has one
 * line and often only part of a column. The flight number is preferred over the
 * carrier because it already carries the airline code ("EY42"), so
 * "Etihad Airways EY42" spends half the width saying it twice. Pure data, so no
 * translation is involved and this can live in a @nextcloud/*-free module.
 * @param seg the flight segment to name
 */
export const segmentLabel = (seg: FlightSegment): string => {
	const flight = seg.flightNumber?.trim() || seg.carrier?.trim() || ''
	const from = seg.origin?.trim() ?? ''
	const to = seg.destination?.trim() ?? ''
	const route = from && to ? `${from} → ${to}` : (from || to)
	return [flight, route].filter(Boolean).join(' ')
}

// One bar per flight leg, or [] when this is not a flight we can break up.
// Anchored on departureLocal, the same field the extraction validates a segment
// by, so a leg that survived extraction can always be placed.
const flightLegItems = (booking: Booking, label: string, color: string | null): CalendarItem[] => {
	if (booking.type !== 'flight') {
		return []
	}
	const segments = booking.details.segments ?? []
	return segments.flatMap((seg, index) => {
		const start = (seg.departureLocal ?? '').slice(0, 10)
		if (!start) {
			return []
		}
		const arrival = (seg.arrivalLocal ?? '').slice(0, 10)
		return [{
			kind: 'booking' as const,
			id: booking.id,
			key: `booking-${booking.id}-${index}`,
			type: booking.type,
			reviewState: booking.reviewState,
			color,
			// Falls back to the booking's own title when the leg is too sparse to
			// name itself — an unlabelled bar would be worse than a repeated one.
			label: segmentLabel(seg) || label,
			start,
			// An overnight leg genuinely spans two days; anything else is one.
			end: arrival && arrival > start ? arrival : start,
		}]
	})
}

/**
 * A booking as one or more bars, or [] when it carries no date and cannot be
 * placed at all.
 *
 * **A multi-leg flight becomes one bar per leg**, each spanning that leg's own
 * departure→arrival dates. The booking's `start_date`/`end_date` is the whole
 * itinerary, so a return trip drawn from it is a single bar covering the fortnight
 * you were away — which says nothing about when you were actually flying and
 * buries every other booking underneath it. The legs are what happen on a day.
 *
 * Everything else — hotels, car rentals, and flights whose segments we could not
 * read — stays one bar from the stored span. A booking with no end date is a
 * single day rather than a zero-length span, so it still draws as something you
 * can see and click.
 * @param booking the booking to place
 * @param label its already-worded title, used when a leg cannot name itself
 * @param color its trip's colour, or null to use the booking-type palette
 */
export const bookingItems = (booking: Booking, label: string, color: string | null = null): CalendarItem[] => {
	const legs = flightLegItems(booking, label, color)
	if (legs.length > 0) {
		return legs
	}

	const start = (booking.startDate ?? '').slice(0, 10)
	if (!start) {
		return []
	}
	const end = (booking.endDate ?? '').slice(0, 10)
	return [{
		kind: 'booking',
		id: booking.id,
		key: `booking-${booking.id}`,
		type: booking.type,
		reviewState: booking.reviewState,
		color,
		label,
		start,
		// An end before the start is meaningless; treat it as a single day.
		end: end && end > start ? end : start,
	}]
}

/**
 * A trip as a bar, from its **derived** span — never trips.start_date, which is
 * user-entered and goes stale the moment a booking is linked (same rule as the
 * Trips grid, so the two cannot disagree). A trip with no dated bookings has no
 * span and therefore no place on the calendar.
 * @param row the trip row, already carrying its derived span
 * @param label its already-worded name
 */
export const tripItem = (row: TripRow, label: string): CalendarItem | null => {
	if (row.start === null) {
		return null
	}
	const start = row.start.slice(0, 10)
	const end = (row.end ?? row.start).slice(0, 10)
	return {
		kind: 'trip',
		id: row.trip.id,
		key: `trip-${row.trip.id}`,
		type: null,
		reviewState: null,
		color: row.trip.color,
		label,
		start,
		end: end > start ? end : start,
	}
}

/** Review states the calendar leaves out unless asked to show everything. */
export const HIDDEN_REVIEW_STATES: ReviewState[] = ['discarded', 'archived']

/**
 * The bookings the calendar draws. Discarded and archived are soft states whose
 * rows survive, but they are not travel you are doing — they would take lanes
 * from the bookings you are.
 * @param items every booking
 * @param showAll true to include discarded and archived as well
 */
export const calendarBookings = (items: Booking[], showAll: boolean): Booking[] =>
	showAll ? items : items.filter((item) => !HIDDEN_REVIEW_STATES.includes(item.reviewState))

/**
 * Drawing order, which is also lane priority: trips first so the grouping reads
 * as the bar above its contents, then earliest, then longest.
 *
 * Longest-first matters because lanes are assigned greedily: a long bar placed
 * after a short one has to skip past it, leaving a gap no other bar can use.
 * @param a one item
 * @param b the other
 */
export const compareItems = (a: CalendarItem, b: CalendarItem): number => {
	if (a.kind !== b.kind) {
		return a.kind === 'trip' ? -1 : 1
	}
	if (a.start !== b.start) {
		return a.start < b.start ? -1 : 1
	}
	if (a.end !== b.end) {
		return a.end > b.end ? -1 : 1
	}
	// By key, not id: a multi-leg flight shares one id across its bars, so id
	// alone is not a total order and legs could swap lanes between renders.
	return a.key < b.key ? -1 : (a.key > b.key ? 1 : 0)
}

// --- laying a week out -----------------------------------------------------

/** One item's run within a single week, positioned and stacked. */
export interface Segment {
	item: CalendarItem
	/** First day column it covers in this week, 0-6. */
	colStart: number
	/** How many columns it covers. */
	colSpan: number
	/** It began in an earlier week — draw the leading edge open. */
	continuesLeft: boolean
	/** It runs on into a later week — draw the trailing edge open. */
	continuesRight: boolean
	/** Which stacked row it sits in, 0-based. */
	lane: number
}

/** A week's worth of grid: what to draw, and what did not fit. */
export interface WeekLayout {
	days: string[]
	segments: Segment[]
	/** Lanes actually drawn, so the caller knows where the week's rows end. */
	lanes: number
	/** Items covering each day that were cut, indexed by day column. */
	hidden: CalendarItem[][]
}

// The columns an item covers in this week, or null when it misses the week.
const clipToWeek = (item: CalendarItem, days: string[]): Omit<Segment, 'lane'> | null => {
	const first = days[0]
	const last = days[days.length - 1]
	if (first === undefined || last === undefined || !overlaps(item, first, last)) {
		return null
	}
	// By comparison rather than indexOf, so a span starting on a day the grid
	// does not contain still clips to the right column instead of vanishing.
	const colStart = Math.max(0, days.findIndex((day) => day >= item.start))
	let colEnd = days.length - 1
	while (colEnd > colStart && days[colEnd]! > item.end) {
		colEnd--
	}
	return {
		item,
		colStart,
		colSpan: colEnd - colStart + 1,
		continuesLeft: item.start < first,
		continuesRight: item.end > last,
	}
}

/**
 * Stack a week's items into lanes and decide what gets cut.
 *
 * First-fit: each item takes the lowest lane free across every column it covers,
 * so a bar never overlaps another and short bars fill the gaps a long one leaves.
 *
 * `maxLanes` is a **soft** cap — a week needing exactly one lane more keeps it,
 * because "+1 more" would occupy the very row it saved while hiding the thing you
 * wanted to see. Beyond that the surplus is cut and counted per day.
 * @param items the items to place; ordered by compareItems for sensible lanes
 * @param days the week's seven days
 * @param maxLanes lanes to keep before collapsing the rest into "+N more"
 */
export const layoutWeek = (
	items: CalendarItem[],
	days: string[],
	maxLanes: number = DEFAULT_MAX_LANES,
): WeekLayout => {
	const placed: Segment[] = []
	// Per lane, the columns already taken — small enough that a scan beats a set.
	const lanes: { from: number, to: number }[][] = []

	for (const item of items) {
		const clipped = clipToWeek(item, days)
		if (clipped === null) {
			continue
		}
		const from = clipped.colStart
		const to = clipped.colStart + clipped.colSpan - 1
		let lane = lanes.findIndex((taken) => !taken.some((run) => run.from <= to && run.to >= from))
		if (lane === -1) {
			lane = lanes.length
			lanes.push([])
		}
		lanes[lane]!.push({ from, to })
		placed.push({ ...clipped, lane })
	}

	const cap = lanes.length <= maxLanes + 1 ? lanes.length : maxLanes
	const hidden: CalendarItem[][] = days.map(() => [])
	for (const segment of placed) {
		if (segment.lane < cap) {
			continue
		}
		for (let col = segment.colStart; col < segment.colStart + segment.colSpan; col++) {
			hidden[col]!.push(segment.item)
		}
	}

	return {
		days,
		segments: placed.filter((segment) => segment.lane < cap),
		lanes: cap,
		hidden,
	}
}

/**
 * Lay out every week of a month in one pass, sorting once for the lot.
 *
 * `maxLanes` may be a function of the week index, which is how "+N more" expands
 * a single crowded week in place without touching the others — cheaper than a
 * popover, and it keeps the hidden items on the day they belong to.
 * @param items every item that could appear; sorted here, so callers need not
 * @param weeks the grid's weeks, from monthWeeks
 * @param maxLanes lanes to keep per week before "+N more", or a per-week function
 */
export const layoutMonth = (
	items: CalendarItem[],
	weeks: string[][],
	maxLanes: number | ((weekIndex: number) => number) = DEFAULT_MAX_LANES,
): WeekLayout[] => {
	const ordered = [...items].sort(compareItems)
	const lanesFor = typeof maxLanes === 'function' ? maxLanes : (): number => maxLanes
	return weeks.map((week, index) => layoutWeek(ordered, week, lanesFor(index)))
}

// --- what the month contains -----------------------------------------------

/** Counts for the toolbar: what this month holds, and what still wants a decision. */
export interface MonthSummary {
	trips: number
	bookings: number
	drafts: number
}

/**
 * Summarise the month being shown — scoped to the month itself, not to the
 * padded grid, so the numbers match the heading above them. This is the one
 * count the navigation's global counters cannot give you.
 * @param items every item on the calendar
 * @param month the month being shown
 */
export const monthSummary = (items: CalendarItem[], month: CalendarMonth): MonthSummary => {
	const { from, to } = monthRange(month)
	const within = items.filter((item) => overlaps(item, from, to))
	// Counted by id, not by bar: a four-leg flight is one booking you decided to
	// take, however many times it appears on the grid.
	const distinct = (kept: (item: CalendarItem) => boolean): number =>
		new Set(within.filter(kept).map((item) => item.id)).size
	return {
		trips: distinct((item) => item.kind === 'trip'),
		bookings: distinct((item) => item.kind === 'booking'),
		drafts: distinct((item) => item.reviewState === 'draft'),
	}
}

/**
 * The earliest still-unreviewed booking in the month, so the summary's draft
 * count can be the button that opens one rather than a number you act on
 * somewhere else.
 * @param items every item on the calendar
 * @param month the month being shown
 */
export const firstDraft = (items: CalendarItem[], month: CalendarMonth): CalendarItem | null => {
	const { from, to } = monthRange(month)
	const drafts = items
		.filter((item) => item.reviewState === 'draft' && overlaps(item, from, to))
		.sort(compareItems)
	return drafts[0] ?? null
}
