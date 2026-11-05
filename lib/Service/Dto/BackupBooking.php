<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One validated booking from a backup file. Dates are local wall-clock
 * `Y-m-d\TH:i:s` strings (no offset, V8).
 */
class BackupBooking {
	/**
	 * @param array<array-key, mixed>|null $details the decoded `bookings.details`
	 */
	public function __construct(
		/** The id on the exporting server: only meaningful inside the file. */
		public readonly int $id,
		public readonly ?int $tripId,
		public readonly string $type,
		public readonly ?string $provider,
		public readonly ?string $bookingReference,
		public readonly ?string $confirmationNumber,
		public readonly ?string $title,
		public readonly string $status,
		public readonly string $reviewState,
		public readonly ?string $sourceMessageId,
		public readonly ?int $duplicateGroupId,
		public readonly ?float $confidence,
		public readonly ?array $details,
		public readonly ?string $startDate,
		public readonly ?string $endDate,
		public readonly ?string $createdAt,
		public readonly ?string $updatedAt,
		public readonly ?string $confirmedAt,
	) {
	}

	/** How to name this booking in a report: the same fallbacks a reader would use. */
	public function label(): string {
		foreach ([$this->title, $this->bookingReference, $this->confirmationNumber, $this->provider] as $candidate) {
			if ($candidate !== null && $candidate !== '') {
				return $candidate;
			}
		}
		return $this->type;
	}
}
