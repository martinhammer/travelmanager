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
 *     confidence: float|null,
 *     details: array<string, mixed>,
 *     startDate: string|null,
 *     endDate: string|null,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     confirmedAt: string|null,
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
 * @psalm-type TravelManagerLog = array{
 *     id: int,
 *     level: string,
 *     step: string,
 *     message: string,
 *     context: string|null,
 *     createdAt: string|null,
 * }
 *
 * @psalm-suppress UnusedClass
 */
class ResponseDefinitions {
}
