import { describe, expect, it } from 'vitest'
import type { BackupReport, BackupSkip } from '../../src/api'
import { backupFileName, exportedAtDate, importReportFileName, importReportText, nothingToImport, refusalMessage, skipRoute } from '../../src/backup'

const report = (imported: Partial<BackupReport['imported']>): BackupReport => ({
	appVersion: '1.18.0',
	exportedAt: '2026-11-05T10:00:00',
	includesEmailText: true,
	inFile: { trips: 1, bookings: 1, messages: 1 },
	existing: { trips: 0, bookings: 0, messages: 0 },
	imported: { trips: 0, bookings: 0, messages: 0, ...imported },
	requeued: 0,
	skipped: [],
})

const skip = (kind: BackupSkip['kind'], existingId: number | null): BackupSkip => ({
	kind,
	reason: 'unfinished_no_text',
	label: 'x',
	existingId,
})

describe('importReportFileName', () => {
	it('carries the local date, like the backup it belongs to', () => {
		expect(importReportFileName(new Date(2026, 10, 5, 23, 30))).toBe('travelmanager-import-report-2026-11-05.txt')
	})
})

describe('importReportText', () => {
	const describeSkip = (s: BackupSkip): string => `because ${s.reason}`
	const linkFor = (s: BackupSkip): string | null => (s.existingId === null ? null : `https://nc/${s.existingId}`)

	it('lists each skipped row with its reason and link, under the summary', () => {
		const text = importReportText(
			['Import report', 'Imported 1 trip'],
			[{ kind: 'booking', reason: 'booking_exists', label: 'AMS → CDG', existingId: 42 }],
			describeSkip,
			linkFor,
		)
		expect(text).toBe('Import report\nImported 1 trip\n\n- AMS → CDG\n  because booking_exists\n  https://nc/42\n')
	})

	it('leaves the link line out when nothing took the row\'s place', () => {
		const text = importReportText(['Report'], [skip('message', null)], describeSkip, linkFor)
		expect(text).toBe('Report\n\n- x\n  because unfinished_no_text\n')
	})

	it('keeps a multi-line subject on one line', () => {
		const text = importReportText(['Report'], [{ ...skip('message', null), label: ' Your\r\n  flight ' }], describeSkip, linkFor)
		expect(text).toContain('- Your flight\n')
	})

	it('is just the summary when nothing was skipped', () => {
		expect(importReportText(['Report', 'All done'], [], describeSkip, linkFor)).toBe('Report\nAll done\n')
	})
})

describe('backupFileName', () => {
	it('carries the local date, so a folder of backups sorts by age', () => {
		expect(backupFileName(new Date(2026, 10, 5, 23, 30))).toBe('travelmanager-backup-2026-11-05.json')
	})
})

describe('exportedAtDate', () => {
	it('reads the offset-less stamp as the UTC instant the server wrote', () => {
		expect(exportedAtDate('2026-11-05T10:00:00').toISOString()).toBe('2026-11-05T10:00:00.000Z')
	})
})

describe('skipRoute', () => {
	it('points at the row the skipped one gave way to', () => {
		expect(skipRoute(skip('trip', 7))).toBe('#/trips/7')
		expect(skipRoute(skip('booking', 42))).toBe('#/bookings/42')
		expect(skipRoute(skip('message', 19))).toBe('#/messages/19')
	})

	it('has nowhere to go when nothing took its place', () => {
		expect(skipRoute(skip('message', null))).toBeNull()
	})
})

describe('refusalMessage', () => {
	const failure = (status: number, message: unknown) => ({ response: { status, data: { ocs: { meta: { message } } } } })

	it('passes on what the server says is wrong with the file', () => {
		expect(refusalMessage(failure(400, 'bookings[3].type: not one of flight'))).toBe('bookings[3].type: not one of flight')
	})

	it('does not present a server failure as a problem with the file', () => {
		expect(refusalMessage(failure(500, 'Internal error'))).toBeNull()
		expect(refusalMessage(failure(403, 'Password confirmation is required'))).toBeNull()
	})

	it('copes with a failure that never reached the server', () => {
		expect(refusalMessage(new Error('Network Error'))).toBeNull()
		expect(refusalMessage(failure(400, ''))).toBeNull()
	})
})

describe('nothingToImport', () => {
	it('is true only when no row of any kind would be written', () => {
		expect(nothingToImport(report({}))).toBe(true)
		expect(nothingToImport(report({ messages: 1 }))).toBe(false)
		expect(nothingToImport(report({ trips: 1 }))).toBe(false)
	})
})
