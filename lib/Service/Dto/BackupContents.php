<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/** A backup file that has passed validation, in the order it will be restored. */
class BackupContents {
	/**
	 * @param list<BackupTrip> $trips
	 * @param list<BackupBooking> $bookings oldest first
	 * @param list<BackupMessage> $messages oldest first
	 */
	public function __construct(
		public readonly string $appVersion,
		public readonly string $exportedAt,
		public readonly bool $includesEmailText,
		public readonly array $trips,
		public readonly array $bookings,
		public readonly array $messages,
	) {
	}
}
