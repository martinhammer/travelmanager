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
 *     title: string|null,
 *     status: string,
 *     confidence: float|null,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     confirmedAt: string|null,
 * }
 *
 * @psalm-type TravelManagerSegment = array{
 *     id: int,
 *     bookingId: int,
 *     sequence: int,
 *     startLocal: string|null,
 *     startTimezone: string|null,
 *     endLocal: string|null,
 *     endTimezone: string|null,
 *     origin: string|null,
 *     destination: string|null,
 *     location: string|null,
 *     flightNumber: string|null,
 *     carrier: string|null,
 *     seat: string|null,
 *     terminal: string|null,
 *     gate: string|null,
 *     confidence: float|null,
 * }
 *
 * @psalm-type TravelManagerBookingDetails = array{
 *     booking: TravelManagerBooking,
 *     segments: list<TravelManagerSegment>,
 * }
 *
 * @psalm-type TravelManagerTrip = array{
 *     id: int,
 *     name: string,
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
 *     rateLimitPerRun: int,
 *     localConcurrency: int,
 * }
 *
 * @psalm-type TravelManagerConnectionTest = array{
 *     ok: bool,
 *     error: string,
 * }
 *
 * @psalm-suppress UnusedClass
 */
class ResponseDefinitions {
}
