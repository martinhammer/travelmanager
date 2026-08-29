import { describe, expect, it } from 'vitest'
import type { Booking } from '../../src/api'
import type { TripRow } from '../../src/trips'
import type { CalendarItem } from '../../src/calendar'
import {
	DEFAULT_MAX_LANES,
	addDays,
	addMonths,
	bookingItems,
	calendarBookings,
	compareItems,
	contrastingText,
	firstDraft,
	MIN_LANES,
	isInMonth,
	layoutMonth,
	lanesForHeight,
	layoutWeek,
	monthOf,
	monthRange,
	monthSummary,
	monthWeeks,
	overlaps,
	sameMonth,
	segmentLabel,
	tripItem,
	weekdayLabels,
} from '../../src/calendar'

const booking = (over: Partial<Booking> = {}): Booking => ({
	id: 1,
	tripId: null,
	type: 'flight',
	provider: null,
	bookingReference: null,
	confirmationNumber: null,
	title: 'BA2551',
	status: 'active',
	reviewState: 'confirmed',
	confidence: null,
	sourceMessageId: null,
	details: {},
	startDate: '2026-09-10T08:00:00',
	endDate: null,
	createdAt: null,
	updatedAt: null,
	confirmedAt: null,
	...over,
})

const item = (over: Partial<CalendarItem> = {}): CalendarItem => ({
	kind: 'booking',
	id: 1,
	key: `booking-${over.id ?? 1}`,
	type: 'flight',
	reviewState: 'confirmed',
	color: null,
	label: 'BA2551',
	start: '2026-09-10',
	end: '2026-09-10',
	...over,
})

// Monday 2026-09-07 through Sunday 2026-09-13.
const WEEK = ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13']

describe('month arithmetic', () => {
	it('adds days across a month boundary', () => {
		expect(addDays('2026-08-31', 1)).toBe('2026-09-01')
		expect(addDays('2026-09-01', -1)).toBe('2026-08-31')
	})

	it('adds days across a leap day', () => {
		expect(addDays('2028-02-28', 1)).toBe('2028-02-29')
		expect(addDays('2027-02-28', 1)).toBe('2027-03-01')
	})

	it('rolls the year over in both directions', () => {
		expect(addMonths({ year: 2026, month: 12 }, 1)).toEqual({ year: 2027, month: 1 })
		expect(addMonths({ year: 2026, month: 1 }, -1)).toEqual({ year: 2025, month: 12 })
		expect(addMonths({ year: 2026, month: 3 }, -14)).toEqual({ year: 2025, month: 1 })
	})

	it('bounds a month, leap years included', () => {
		expect(monthRange({ year: 2026, month: 9 })).toEqual({ from: '2026-09-01', to: '2026-09-30' })
		expect(monthRange({ year: 2028, month: 2 }).to).toBe('2028-02-29')
		expect(monthRange({ year: 2027, month: 2 }).to).toBe('2027-02-28')
	})

	it('takes the month from the viewer’s own calendar date, not UTC', () => {
		// 23:30 on 30 September in a zone ahead of UTC is still September here;
		// toISOString would call it October.
		const local = new Date(2026, 8, 30, 23, 30)
		expect(monthOf(local)).toEqual({ year: 2026, month: 9 })
	})

	it('compares months by both parts', () => {
		expect(sameMonth({ year: 2026, month: 9 }, { year: 2026, month: 9 })).toBe(true)
		expect(sameMonth({ year: 2026, month: 9 }, { year: 2027, month: 9 })).toBe(false)
	})
})

describe('monthWeeks', () => {
	it('pads to whole weeks from the locale’s first day', () => {
		// 1 September 2026 is a Tuesday, so a Monday-first grid leads with 31 Aug.
		const weeks = monthWeeks({ year: 2026, month: 9 }, 1)
		expect(weeks[0][0]).toBe('2026-08-31')
		expect(weeks.every((week) => week.length === 7)).toBe(true)
		expect(weeks[weeks.length - 1][6]).toBe('2026-10-04')
	})

	it('starts the same month on a different day for a Sunday-first locale', () => {
		expect(monthWeeks({ year: 2026, month: 9 }, 0)[0][0]).toBe('2026-08-30')
	})

	it('covers every day of the month exactly once', () => {
		const days = monthWeeks({ year: 2026, month: 2 }, 1).flat()
		expect(days.filter((day) => day.startsWith('2026-02'))).toHaveLength(28)
		expect(new Set(days).size).toBe(days.length)
	})

	it('gives a month that begins on the first weekday no leading week', () => {
		// 1 June 2026 is a Monday.
		expect(monthWeeks({ year: 2026, month: 6 }, 1)[0][0]).toBe('2026-06-01')
	})

	it('marks padding days as outside the month', () => {
		expect(isInMonth('2026-08-31', { year: 2026, month: 9 })).toBe(false)
		expect(isInMonth('2026-09-01', { year: 2026, month: 9 })).toBe(true)
		expect(isInMonth('2026-09-30', { year: 2026, month: 9 })).toBe(true)
	})

	it('rotates weekday names into the locale’s order', () => {
		const names = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
		expect(weekdayLabels(names, 0)[0]).toBe('Su')
		expect(weekdayLabels(names, 1)).toEqual(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'])
		expect(weekdayLabels(names, 6)[0]).toBe('Sa')
	})
})

describe('what goes on the grid', () => {
	it('places a booking with no end date on a single day', () => {
		expect(bookingItems(booking({ type: 'accommodation' }), 'Hotel')[0])
			.toMatchObject({ start: '2026-09-10', end: '2026-09-10', kind: 'booking' })
	})

	it('spans a booking that has an end date', () => {
		const placed = bookingItems(booking({ type: 'accommodation', startDate: '2026-09-10', endDate: '2026-09-14' }), 'Hotel')
		expect(placed[0]).toMatchObject({ start: '2026-09-10', end: '2026-09-14' })
	})

	it('treats an end before the start as a single day rather than a negative span', () => {
		const placed = bookingItems(booking({ type: 'accommodation', startDate: '2026-09-10', endDate: '2026-09-02' }), 'Odd')
		expect(placed[0].end).toBe('2026-09-10')
	})

	it('drops an undated booking, which cannot be placed', () => {
		expect(bookingItems(booking({ startDate: null }), 'Nowhere')).toEqual([])
	})

	// The real shape that motivated per-leg bars: AMS→AUH→KUL out, KUL→AUH→AMS
	// back ten days later. Stored as one booking spanning 2 → 12 September.
	const returnTrip = booking({
		id: 51,
		startDate: '2026-09-02T21:55:00',
		endDate: '2026-09-12T19:10:00',
		details: {
			segments: [
				{ carrier: 'Etihad Airways', flightNumber: 'EY42', origin: 'AMS', destination: 'AUH', departureLocal: '2026-09-02T21:55:00', arrivalLocal: '2026-09-03T06:30:00' },
				{ carrier: 'Etihad Airways', flightNumber: 'EY486', origin: 'AUH', destination: 'KUL', departureLocal: '2026-09-03T08:25:00', arrivalLocal: '2026-09-03T19:45:00' },
				{ carrier: 'Etihad Airways', flightNumber: 'EY489', origin: 'KUL', destination: 'AUH', departureLocal: '2026-09-12T09:50:00', arrivalLocal: '2026-09-12T12:50:00' },
				{ carrier: 'Etihad Airways', flightNumber: 'EY41', origin: 'AUH', destination: 'AMS', departureLocal: '2026-09-12T14:05:00', arrivalLocal: '2026-09-12T19:10:00' },
			],
		},
	})

	it('draws one bar per flight leg, not one across the whole itinerary', () => {
		const placed = bookingItems(returnTrip, 'Etihad booking 7WKHFH')
		expect(placed.map((i) => [i.start, i.end])).toEqual([
			// Overnight, so genuinely two days.
			['2026-09-02', '2026-09-03'],
			['2026-09-03', '2026-09-03'],
			['2026-09-12', '2026-09-12'],
			['2026-09-12', '2026-09-12'],
		])
		// Never the stored 2 → 12 span, which is the fortnight away, not a flight.
		expect(placed.some((i) => i.start === '2026-09-02' && i.end === '2026-09-12')).toBe(false)
	})

	it('names each leg by its own flight and route', () => {
		expect(bookingItems(returnTrip, 'ignored').map((i) => i.label)).toEqual([
			'EY42 AMS → AUH', 'EY486 AUH → KUL', 'EY489 KUL → AUH', 'EY41 AUH → AMS',
		])
	})

	it('carries the trip colour onto every leg, for the bar to tint itself', () => {
		expect(bookingItems(returnTrip, 'x', '#c9349f').map((i) => i.color))
			.toEqual(['#c9349f', '#c9349f', '#c9349f', '#c9349f'])
	})

	it('leaves colour null when there is no trip, so the type palette stands', () => {
		expect(bookingItems(booking({ type: 'accommodation' }), 'Hotel')[0].color).toBeNull()
	})

	it('takes a trip’s own colour for its bar', () => {
		const row = {
			trip: { id: 7, name: 'Lisbon', type: null, color: '#bf678b', startDate: null, endDate: null, notes: null },
			bookings: [], start: '2026-09-10', end: '2026-09-14', types: [], period: 'future',
		} as TripRow
		expect(tripItem(row, 'Lisbon')?.color).toBe('#bf678b')
	})

	it('gives every leg the same id but a distinct key', () => {
		const placed = bookingItems(returnTrip, 'x')
		// One id, so any leg opens the booking and all four highlight together.
		expect(new Set(placed.map((i) => i.id))).toEqual(new Set([51]))
		expect(new Set(placed.map((i) => i.key)).size).toBe(4)
	})

	it('falls back to the booking’s span when a flight has no readable segments', () => {
		const placed = bookingItems(booking({ startDate: '2026-09-10', endDate: '2026-09-14', details: {} }), 'Flight')
		expect(placed).toHaveLength(1)
		expect(placed[0]).toMatchObject({ start: '2026-09-10', end: '2026-09-14', key: 'booking-1' })
	})

	it('skips a leg with no departure, keeping the ones that have it', () => {
		const placed = bookingItems(booking({
			details: {
				segments: [
					{ flightNumber: 'AA1', origin: 'LHR', destination: 'JFK', departureLocal: '2026-09-10T08:00:00' },
					{ flightNumber: 'AA2', origin: 'JFK', destination: 'LAX' },
				],
			},
		}), 'Flight')
		expect(placed.map((i) => i.label)).toEqual(['AA1 LHR → JFK'])
	})

	it('names a leg it cannot describe after the booking instead of leaving it blank', () => {
		const placed = bookingItems(booking({
			details: { segments: [{ departureLocal: '2026-09-10T08:00:00' }] },
		}), 'Etihad booking 7WKHFH')
		expect(placed[0].label).toBe('Etihad booking 7WKHFH')
	})

	it('prefers the flight number to the carrier, which repeats the airline code', () => {
		expect(segmentLabel({ carrier: 'Etihad Airways', flightNumber: 'EY42', origin: 'AMS', destination: 'AUH' }))
			.toBe('EY42 AMS → AUH')
		expect(segmentLabel({ carrier: 'Etihad Airways', origin: 'AMS', destination: 'AUH' }))
			.toBe('Etihad Airways AMS → AUH')
		expect(segmentLabel({ flightNumber: 'EY42' })).toBe('EY42')
		expect(segmentLabel({})).toBe('')
	})

	it('leaves non-flight bookings as a single bar', () => {
		// A hotel's stay is one continuous thing; there is nothing to split.
		expect(bookingItems(booking({ type: 'accommodation', startDate: '2026-09-02', endDate: '2026-09-12' }), 'Hotel'))
			.toHaveLength(1)
	})

	it('uses a trip’s derived span, not its stored dates', () => {
		const row = {
			// Stored dates deliberately disagree: they are user-entered and stale.
			trip: { id: 7, name: 'Lisbon', type: null, color: null, startDate: '2020-01-01', endDate: '2020-01-09', notes: null },
			bookings: [],
			start: '2026-09-10',
			end: '2026-09-14',
			types: ['flight'],
			period: 'future',
		} as TripRow
		expect(tripItem(row, 'Lisbon')).toMatchObject({ start: '2026-09-10', end: '2026-09-14', kind: 'trip' })
	})

	it('drops a trip whose bookings carry no dates', () => {
		const row = {
			trip: { id: 8, name: 'Someday', type: null, color: null, startDate: null, endDate: null, notes: null },
			bookings: [],
			start: null,
			end: null,
			types: [],
			period: 'undated',
		} as TripRow
		expect(tripItem(row, 'Someday')).toBeNull()
	})

	it('leaves out discarded and archived bookings unless asked for everything', () => {
		const items = [
			booking({ id: 1, reviewState: 'draft' }),
			booking({ id: 2, reviewState: 'confirmed' }),
			booking({ id: 3, reviewState: 'discarded' }),
			booking({ id: 4, reviewState: 'archived' }),
		]
		expect(calendarBookings(items, false).map((b) => b.id)).toEqual([1, 2])
		expect(calendarBookings(items, true)).toHaveLength(4)
	})

	it('draws trips above bookings, then earliest, then longest', () => {
		const ordered = [
			item({ id: 3, kind: 'booking', start: '2026-09-08' }),
			item({ id: 2, kind: 'booking', start: '2026-09-07', end: '2026-09-07' }),
			item({ id: 1, kind: 'trip', start: '2026-09-09' }),
			item({ id: 4, kind: 'booking', start: '2026-09-07', end: '2026-09-09' }),
		].sort(compareItems)
		expect(ordered.map((i) => i.id)).toEqual([1, 4, 2, 3])
	})

	it('detects overlap at the boundaries', () => {
		expect(overlaps(item({ start: '2026-09-01', end: '2026-09-05' }), '2026-09-05', '2026-09-30')).toBe(true)
		expect(overlaps(item({ start: '2026-09-01', end: '2026-09-04' }), '2026-09-05', '2026-09-30')).toBe(false)
	})
})

describe('colour', () => {
	it('puts dark text on a pale colour and white on a deep one', () => {
		// Nextcloud's picker offers both; the type palette only ever needed white.
		expect(contrastingText('#f0e68c')).toBe('#1a1a1a')
		expect(contrastingText('#2b6cb0')).toBe('#ffffff')
		expect(contrastingText('#ffffff')).toBe('#1a1a1a')
		expect(contrastingText('#000000')).toBe('#ffffff')
	})

	it('weights green over blue, as the eye does', () => {
		// The same channel value in each: green reads as bright enough to need dark
		// text, blue nowhere near. A plain mean of the channels would call them equal.
		expect(contrastingText('#00e000')).toBe('#1a1a1a')
		expect(contrastingText('#0000e0')).toBe('#ffffff')
	})

	it('keeps white when it cannot read the colour', () => {
		expect(contrastingText('rgb(1,2,3)')).toBe('#ffffff')
	})
})

describe('layoutWeek', () => {
	it('positions a single-day item in one column', () => {
		const { segments } = layoutWeek([item({ start: '2026-09-10', end: '2026-09-10' })], WEEK)
		expect(segments[0]).toMatchObject({
			colStart: 3, colSpan: 1, continuesLeft: false, continuesRight: false, lane: 0,
		})
	})

	it('clips a span that starts before the week and marks it as continuing', () => {
		const { segments } = layoutWeek([item({ start: '2026-09-04', end: '2026-09-09' })], WEEK)
		expect(segments[0]).toMatchObject({
			colStart: 0, colSpan: 3, continuesLeft: true, continuesRight: false,
		})
	})

	it('clips a span that runs past the week and marks it as continuing', () => {
		const { segments } = layoutWeek([item({ start: '2026-09-11', end: '2026-09-20' })], WEEK)
		expect(segments[0]).toMatchObject({
			colStart: 4, colSpan: 3, continuesLeft: false, continuesRight: true,
		})
	})

	it('marks both edges for a span that covers the whole week', () => {
		const { segments } = layoutWeek([item({ start: '2026-09-01', end: '2026-09-30' })], WEEK)
		expect(segments[0]).toMatchObject({
			colStart: 0, colSpan: 7, continuesLeft: true, continuesRight: true,
		})
	})

	it('omits an item that misses the week entirely', () => {
		expect(layoutWeek([item({ start: '2026-10-01', end: '2026-10-02' })], WEEK).segments).toEqual([])
	})

	it('stacks overlapping items into separate lanes', () => {
		const { segments } = layoutWeek([
			item({ id: 1, start: '2026-09-07', end: '2026-09-11' }),
			item({ id: 2, start: '2026-09-09', end: '2026-09-13' }),
		], WEEK)
		expect(segments.map((s) => s.lane)).toEqual([0, 1])
	})

	it('reuses a lane for items that do not overlap', () => {
		const { segments } = layoutWeek([
			item({ id: 1, start: '2026-09-07', end: '2026-09-08' }),
			item({ id: 2, start: '2026-09-11', end: '2026-09-12' }),
		], WEEK)
		expect(segments.map((s) => s.lane)).toEqual([0, 0])
	})

	it('treats items that merely touch end-to-start as overlapping the same day', () => {
		const { segments } = layoutWeek([
			item({ id: 1, start: '2026-09-07', end: '2026-09-09' }),
			item({ id: 2, start: '2026-09-09', end: '2026-09-11' }),
		], WEEK)
		expect(segments.map((s) => s.lane)).toEqual([0, 1])
	})

	// Counts are expressed against DEFAULT_MAX_LANES rather than written out, so
	// retuning the cap for a denser grid does not mean rewriting the arithmetic.
	const ids = (n: number): number[] => Array.from({ length: n }, (_, i) => i + 1)
	const onOneDay = (n: number): CalendarItem[] =>
		ids(n).map((id) => item({ id, key: `booking-${id}`, start: '2026-09-10', end: '2026-09-10' }))

	it('keeps one lane over the cap rather than hiding a single item behind “+1 more”', () => {
		const { segments, lanes, hidden } = layoutWeek(onOneDay(DEFAULT_MAX_LANES + 1), WEEK, DEFAULT_MAX_LANES)
		expect(lanes).toBe(DEFAULT_MAX_LANES + 1)
		expect(segments).toHaveLength(DEFAULT_MAX_LANES + 1)
		expect(hidden.flat()).toEqual([])
	})

	it('cuts the surplus beyond the tolerance and counts it per day', () => {
		const { segments, lanes, hidden } = layoutWeek(onOneDay(DEFAULT_MAX_LANES + 3), WEEK, DEFAULT_MAX_LANES)
		expect(lanes).toBe(DEFAULT_MAX_LANES)
		expect(segments).toHaveLength(DEFAULT_MAX_LANES)
		expect(hidden[3]).toHaveLength(3)
		// Only the day they fall on is affected.
		expect(hidden[0]).toEqual([])
	})

	it('counts a cut span as hidden on every day it covers', () => {
		const spanning = ids(DEFAULT_MAX_LANES + 2)
			.map((id) => item({ id, key: `booking-${id}`, start: '2026-09-07', end: '2026-09-13' }))
		const short = item({ id: 99, key: 'booking-99', start: '2026-09-08', end: '2026-09-09' })
		const { hidden } = layoutWeek([...spanning, short], WEEK, DEFAULT_MAX_LANES)
		// The two spanning items past the cap are hidden on every day of the week…
		expect(hidden[0]).toHaveLength(2)
		// …and the short one, which lands in a lane past it too, only on its own.
		expect(hidden[1].map((i) => i.id)).toEqual([DEFAULT_MAX_LANES + 1, DEFAULT_MAX_LANES + 2, 99])
	})

	it('shows everything when the cap is lifted', () => {
		const items = onOneDay(DEFAULT_MAX_LANES + 3)
		const { segments, hidden } = layoutWeek(items, WEEK, Number.MAX_SAFE_INTEGER)
		expect(segments).toHaveLength(DEFAULT_MAX_LANES + 3)
		expect(hidden.flat()).toEqual([])
	})
})

describe('lanesForHeight', () => {
	// The geometry calendar.css actually declares.
	const metrics = { head: 22, foot: 3, bar: 20, gap: 2 }

	// A floor of 1 isolates the arithmetic; MIN_LANES is exercised on its own below.
	const fits = (height: number): number => lanesForHeight(height, metrics, 1)

	it('fits as many whole lanes as the height allows', () => {
		// 22 head + 3 foot leaves 85 of 110, and the pitch is 22.
		expect(fits(110)).toBe(3)
		expect(fits(113)).toBe(4)
		expect(fits(250)).toBe(10)
	})

	it('never rounds a partial lane up into one that would overflow', () => {
		// 22 + 3 + 4x22 = 113 exactly, so 112 is one pixel short of a fourth lane.
		expect(fits(112)).toBe(3)
	})

	it('shows more on a tall grid than a short one — the point of measuring', () => {
		expect(fits(300)).toBeGreaterThan(fits(150))
	})

	it('never collapses below the floor, since a week may grow past its share', () => {
		// A row is minmax(min-content, 1fr): it takes the height it needs and the
		// grid scrolls, so capping at the nominal share would hide bookings the
		// layout was perfectly willing to draw.
		expect(lanesForHeight(112, metrics)).toBe(MIN_LANES)
		expect(lanesForHeight(0, metrics)).toBe(MIN_LANES)
		expect(lanesForHeight(-500, metrics)).toBe(MIN_LANES)
	})

	it('raises the count above the floor when the room is there', () => {
		expect(lanesForHeight(300, metrics)).toBeGreaterThan(MIN_LANES)
	})

	it('survives geometry it cannot use, rather than dividing by zero', () => {
		expect(lanesForHeight(500, { head: 0, foot: 0, bar: 0, gap: 0 })).toBe(MIN_LANES)
	})
})

describe('layoutMonth', () => {
	const weeks = monthWeeks({ year: 2026, month: 9 }, 1)

	it('splits one span across the weeks it crosses, marking the join', () => {
		const layouts = layoutMonth([item({ start: '2026-09-10', end: '2026-09-16' })], weeks)
		const drawn = layouts.flatMap((week) => week.segments)
		expect(drawn).toHaveLength(2)
		expect(drawn[0]).toMatchObject({ colSpan: 4, continuesLeft: false, continuesRight: true })
		expect(drawn[1]).toMatchObject({ colStart: 0, colSpan: 3, continuesLeft: true, continuesRight: false })
	})

	it('sorts once for every week, so lanes stay consistent down the month', () => {
		const layouts = layoutMonth([
			item({ id: 2, kind: 'booking', start: '2026-09-07', end: '2026-09-20' }),
			item({ id: 1, kind: 'trip', start: '2026-09-07', end: '2026-09-20' }),
		], weeks)
		const withBars = layouts.filter((week) => week.segments.length > 0)
		for (const week of withBars) {
			expect(week.segments.find((s) => s.item.kind === 'trip')?.lane).toBe(0)
		}
	})

	it('lets one week be expanded without lifting the cap on the others', () => {
		const n = DEFAULT_MAX_LANES + 2
		const crowd = (from: number, day: string): CalendarItem[] =>
			Array.from({ length: n }, (_, i) => item({ id: from + i, key: `booking-${from + i}`, start: day, end: day }))
		const layouts = layoutMonth(
			[...crowd(1, '2026-09-01'), ...crowd(100, '2026-09-15')],
			weeks,
			(index) => index === 0 ? Number.MAX_SAFE_INTEGER : DEFAULT_MAX_LANES,
		)
		expect(layouts[0].segments).toHaveLength(n)
		expect(layouts[0].hidden.flat()).toEqual([])
		expect(layouts[2].hidden.flat()).toHaveLength(n - DEFAULT_MAX_LANES)
	})
})

describe('monthSummary', () => {
	const september = { year: 2026, month: 9 }

	it('counts a multi-leg flight once, however many bars it draws', () => {
		// Four bars, one decision to make about them.
		const legs = [0, 1, 2, 3].map((n) => item({
			id: 51, key: `booking-51-${n}`, reviewState: 'draft', start: '2026-09-12',
		}))
		expect(monthSummary(legs, september)).toEqual({ trips: 0, bookings: 1, drafts: 1 })
	})

	it('counts what the month holds, drafts separately', () => {
		expect(monthSummary([
			item({ id: 1, kind: 'trip', reviewState: null, start: '2026-09-10', end: '2026-09-14' }),
			item({ id: 2, reviewState: 'draft', start: '2026-09-10' }),
			item({ id: 3, reviewState: 'confirmed', start: '2026-09-12' }),
		], september)).toEqual({ trips: 1, bookings: 2, drafts: 1 })
	})

	it('counts a span that only reaches into the month', () => {
		expect(monthSummary([
			item({ id: 1, start: '2026-08-28', end: '2026-09-02' }),
			item({ id: 2, start: '2026-09-29', end: '2026-10-04' }),
			item({ id: 3, start: '2026-10-05', end: '2026-10-06' }),
		], september).bookings).toBe(2)
	})

	it('is scoped to the month, not to the padded grid', () => {
		// 31 August shows on September's grid but is not part of the month.
		expect(monthSummary([item({ start: '2026-08-31', end: '2026-08-31' })], september).bookings).toBe(0)
	})

	it('offers the earliest draft in the month, and nothing when there is none', () => {
		const items = [
			item({ id: 1, reviewState: 'draft', start: '2026-09-20' }),
			item({ id: 2, reviewState: 'draft', start: '2026-09-04' }),
			item({ id: 3, reviewState: 'confirmed', start: '2026-09-01' }),
		]
		expect(firstDraft(items, september)?.id).toBe(2)
		expect(firstDraft(items, { year: 2026, month: 11 })).toBeNull()
	})
})
