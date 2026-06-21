<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One validated booking from an LLM extraction, with its segments.
 */
class ExtractedBooking {
	/**
	 * @param ExtractedSegment[] $segments
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $provider,
		public readonly ?string $bookingReference,
		public readonly string $status,
		public readonly ?string $title,
		public readonly array $segments,
		public readonly ?float $confidence = null,
	) {
	}
}
