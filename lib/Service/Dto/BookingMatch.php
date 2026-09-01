<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * An existing booking that an incoming extraction resembles, and how strongly.
 *
 * {@see $decisive} is the whole point of the type. A decisive match suppresses
 * the incoming booking — it is never written — so widening the match rules
 * turns every false positive into silent data loss. Anything short of decisive
 * therefore still gets stored, and is merely *reported* against the booking it
 * might duplicate, which is the same discipline `ExtractionIssue` applies to
 * rejected extractions: never swallow, always say so.
 */
class BookingMatch {
	/** Identifiers in common: the same booking under a different email. */
	public const REASON_IDENTIFIER = 'matched_identifier';
	/** No identifier in common, but the same operator on the same day. */
	public const REASON_ITINERARY = 'matched_itinerary';
	/** Evidence points both ways — stored anyway, and flagged. */
	public const REASON_POSSIBLE = 'possible_duplicate';

	public function __construct(
		public readonly MatchCandidate $candidate,
		/** One of the REASON_* slugs. */
		public readonly string $reason,
		/** True = the same booking; do not write the incoming one. */
		public readonly bool $decisive,
		/** What agreed, named in a phrase: "booking reference WDP1UANA". */
		public readonly string $evidence,
	) {
	}
}
