import type { BackupReport, BackupSkip } from './api'
import { localDate } from './grid'

/**
 * Named after the day it was made, so that a folder of backups sorts by age.
 * @param now the moment of the export
 */
export const backupFileName = (now: Date = new Date()): string =>
	`travelmanager-backup-${localDate(now)}.json`

/**
 * Dated like the backup it belongs to, so the two sort side by side.
 * @param now the moment of the download
 */
export const importReportFileName = (now: Date = new Date()): string =>
	`travelmanager-import-report-${localDate(now)}.txt`

/**
 * The server writes this from its UTC clock without an offset, and unlike a travel
 * time it is an instant, so it is the one date here that should convert.
 * @param exportedAt the file's `exportedAt`
 */
export const exportedAtDate = (exportedAt: string): Date => new Date(`${exportedAt}Z`)

/**
 * Where the skipped row's counterpart lives in the app, or null when it has none.
 * @param skip the skipped row
 */
export const skipRoute = (skip: BackupSkip): string | null => {
	if (skip.existingId === null) {
		return null
	}
	const list = { trip: 'trips', booking: 'bookings', message: 'messages' }[skip.kind]
	return `#/${list}/${skip.existingId}`
}

/**
 * The reason a server gave for refusing a file, which is written for the user and
 * says exactly what is wrong with it, or null if the failure came from elsewhere.
 * Any 400 the app words for the user reads the same way, so the settings save
 * uses it for a refused mailbox address too.
 * @param error whatever the request threw
 */
export const refusalMessage = (error: unknown): string | null => {
	const response = (error as { response?: { status?: number, data?: { ocs?: { meta?: { message?: unknown } } } } }).response
	const message = response?.data?.ocs?.meta?.message
	return response?.status === 400 && typeof message === 'string' && message !== '' ? message : null
}

/**
 * Whether a report has nothing to import: every row is already here, or the file
 * is empty. Importing would still be harmless, but it is worth saying so.
 * @param report the preview
 */
export const nothingToImport = (report: BackupReport): boolean =>
	report.imported.trips + report.imported.bookings + report.imported.messages === 0

/**
 * An import report as plain text: one block per skipped row. The wording is passed
 * in because this module stays free of `@nextcloud/*` (§7). A label comes from an
 * email subject, so its whitespace is collapsed to keep each row's block intact.
 * @param summary the lines above the list, already worded
 * @param skipped the rows that were not written
 * @param describe why a row was skipped
 * @param linkFor where its counterpart can be opened, if it has one
 */
export const importReportText = (
	summary: string[],
	skipped: BackupSkip[],
	describe: (skip: BackupSkip) => string,
	linkFor: (skip: BackupSkip) => string | null,
): string => {
	const blocks = skipped.map((skip) => {
		const link = linkFor(skip)
		return [
			`- ${skip.label.replace(/\s+/g, ' ').trim()}`,
			`  ${describe(skip)}`,
			...(link === null ? [] : [`  ${link}`]),
		].join('\n')
	})
	return [summary.join('\n'), ...blocks].join('\n\n') + '\n'
}
