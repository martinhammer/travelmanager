<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One validated dated item from an LLM extraction. Times are local wall-clock
 * strings (no offset, see V8); the timezone name, if any, is informational.
 */
class ExtractedSegment {
	public function __construct(
		public readonly string $startLocal,
		public readonly ?string $startTimezone = null,
		public readonly ?string $endLocal = null,
		public readonly ?string $endTimezone = null,
		public readonly ?string $origin = null,
		public readonly ?string $destination = null,
		public readonly ?string $location = null,
		public readonly ?string $flightNumber = null,
		public readonly ?string $carrier = null,
		public readonly ?string $seat = null,
		public readonly ?string $terminal = null,
		public readonly ?string $gate = null,
		public readonly array $extra = [],
		public readonly ?float $confidence = null,
	) {
	}
}
