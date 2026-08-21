<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * Something the extraction rejected or trimmed away.
 *
 * The validator silently discards anything it cannot anchor to a date (see the
 * anti-hallucination rule in ExtractionService). Without a record of that, a
 * booking email whose dates the model mangled is indistinguishable from a
 * newsletter that contained no booking at all — both simply yield zero
 * bookings. Issues make that difference visible in the activity log and let the
 * pipeline mark the message as retry-worthy rather than "nothing here".
 */
class ExtractionIssue {
	// Whole-booking drops.
	public const REASON_UNKNOWN_TYPE = 'unknown_type';
	public const REASON_MALFORMED = 'malformed_entry';
	public const REASON_MISSING_DEPARTURE = 'missing_departure';
	public const REASON_MISSING_PICKUP = 'missing_pickup';
	public const REASON_MISSING_CHECKIN = 'missing_checkin';
	// Partial loss: the booking survived, but part of it did not.
	public const REASON_PARTIAL_SEGMENTS = 'partial_segments';
	/**
	 * We changed the model's output before parsing it. Never silent: a rising
	 * rate of repairs is how you notice a provider or model degrading, and it
	 * would otherwise look like a clean run.
	 */
	public const REASON_REPAIRED_JSON = 'repaired_json';

	public function __construct(
		/** One of the REASON_* slugs. */
		public readonly string $reason,
		/** Human-readable sentence for the activity log. */
		public readonly string $description,
		/** True when the whole booking was discarded, false for partial loss. */
		public readonly bool $dropped,
	) {
	}
}
