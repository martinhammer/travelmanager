<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * An existing booking, flattened into plain values for {@see \OCA\TravelManager\Service\BookingMatcher}.
 *
 * The matcher must stay free of OCP types so it runs under the standalone
 * PHPUnit bootstrap (§7 of CLAUDE.md), and `Db\Booking` is a `QBMapper` entity.
 * BookingService therefore maps entities to this DTO on the way in. It carries
 * `details` already decoded, because every field the matcher needs beyond the
 * header — the rental company behind the broker, the property name behind the
 * agency, the carrier behind the ticket — lives in that JSON.
 */
class MatchCandidate {
	/**
	 * @param array<array-key, mixed> $details decoded `bookings.details`
	 * @param ?string $startDate local wall-clock `Y-m-d\TH:i:s` (no offset, V8)
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly ?string $provider,
		public readonly ?string $bookingReference,
		public readonly ?string $confirmationNumber,
		public readonly array $details,
		public readonly ?string $startDate,
		/** Only for the prose: "you already discarded this" is worth reading. */
		public readonly string $reviewState,
	) {
	}
}
