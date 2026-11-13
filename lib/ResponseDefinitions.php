<?php

declare(strict_types=1);

namespace OCA\TravelManager;

/**
 * Shared response shapes for the OpenAPI spec / typed clients.
 *
 * @psalm-type TravelManagerBooking = array{
 *     id: int,
 *     tripId: int|null,
 *     type: string,
 *     provider: string|null,
 *     bookingReference: string|null,
 *     confirmationNumber: string|null,
 *     title: string|null,
 *     status: string,
 *     reviewState: string,
 *     confidence: float|null,
 *     sourceMessageId: string|null,
 *     duplicateGroupId: int|null,
 *     details: array<string, mixed>,
 *     startDate: string|null,
 *     endDate: string|null,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     confirmedAt: string|null,
 * }
 *
 * One PDF attachment of an email and what became of it at ingestion. `outcome`
 * is read / no_text / encrypted / failed / crashed / too_large / too_many /
 * disabled; the counts are null when the file was never opened.
 *
 * @psalm-type TravelManagerAttachment = array{
 *     filename: string|null,
 *     pages: int|null,
 *     pagesRead: int|null,
 *     chars: int|null,
 *     outcome: string,
 *     truncated: bool,
 * }
 *
 * @psalm-type TravelManagerMessage = array{
 *     id: int,
 *     mailbox: string,
 *     messageId: string,
 *     subject: string|null,
 *     sender: string|null,
 *     status: string,
 *     failureKind: string|null,
 *     issueReasons: list<string>,
 *     relatedBookingIds: list<int>,
 *     error: string|null,
 *     lastResponse: string|null,
 *     attempts: int,
 *     canRetry: bool,
 *     discarded: bool,
 *     attachments: list<TravelManagerAttachment>,
 *     sentAt: string|null,
 *     processedAt: string|null,
 * }
 *
 * The retained email body, fetched on its own so the list payload stays lean —
 * it is up to 20000 chars per message and the list returns up to 200 of them.
 *
 * @psalm-type TravelManagerMessageBody = array{
 *     id: int,
 *     bodyText: string|null,
 * }
 *
 * @psalm-type TravelManagerTrip = array{
 *     id: int,
 *     name: string,
 *     type: string|null,
 *     color: string|null,
 *     startDate: string|null,
 *     endDate: string|null,
 *     notes: string|null,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 * }
 *
 * @psalm-type TravelManagerUserSettings = array{
 *     enabled: bool,
 *     imapHost: string,
 *     imapPort: int,
 *     imapSecurity: string,
 *     imapUser: string,
 *     mailbox: string,
 *     intervalMinutes: int,
 *     hasPassword: bool,
 *     isConfigured: bool,
 * }
 *
 * @psalm-type TravelManagerAdminSettings = array{
 *     enabled: bool,
 *     fetchPerRun: int,
 *     maxInFlight: int,
 *     maxPerHour: int,
 *     pdfAttachments: bool,
 *     verifyImapCertificates: bool,
 * }
 *
 * @psalm-type TravelManagerConnectionTest = array{
 *     ok: bool,
 *     error: string,
 * }
 *
 * @psalm-type TravelManagerLog = array{
 *     id: int,
 *     level: string,
 *     step: string,
 *     message: string,
 *     context: string|null,
 *     createdAt: string|null,
 * }
 *
 * @psalm-type TravelManagerBackupCounts = array{
 *     trips: int,
 *     bookings: int,
 *     messages: int,
 * }
 *
 * @psalm-type TravelManagerBackupSkip = array{
 *     kind: string,
 *     reason: string,
 *     label: string,
 *     existingId: int|null,
 * }
 *
 * @psalm-type TravelManagerBackupReport = array{
 *     appVersion: string,
 *     exportedAt: string,
 *     includesEmailText: bool,
 *     inFile: TravelManagerBackupCounts,
 *     existing: TravelManagerBackupCounts,
 *     imported: TravelManagerBackupCounts,
 *     requeued: int,
 *     skipped: list<TravelManagerBackupSkip>,
 * }
 *
 * @psalm-suppress UnusedClass
 */
class ResponseDefinitions {
}
