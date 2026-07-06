<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One validated booking from an LLM extraction.
 *
 * The type-specific structure (flight legs + passengers, car supplier/features,
 * hotel stay, …) is carried as a validated associative array in {@see $details}
 * and persisted verbatim as JSON. {@see $startDate}/{@see $endDate} are the
 * derived local wall-clock span (no offset, see V8) used for list ordering.
 */
class ExtractedBooking {
	/**
	 * @param array<array-key, mixed> $details
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $provider,
		public readonly ?string $bookingReference,
		public readonly ?string $confirmationNumber,
		public readonly string $status,
		public readonly ?string $title,
		public readonly array $details,
		public readonly ?string $startDate = null,
		public readonly ?string $endDate = null,
		public readonly ?float $confidence = null,
	) {
	}
}
