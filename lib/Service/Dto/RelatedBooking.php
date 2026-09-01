<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * An existing booking that a later email matched but did not touch.
 *
 * Carries the id as well as the prose. The description alone was all that used
 * to be kept — written into `messages.error` as "matches the existing booking
 * #12" — which meant the link existed only inside a sentence, so the UI could
 * report the relationship but never offer to open the booking. Same shape of
 * problem that `issue_reasons` solved for extraction issues.
 */
class RelatedBooking {
	public function __construct(
		public readonly int $bookingId,
		/** Human-readable line for the activity log and the message row. */
		public readonly string $description,
		/**
		 * The BookingMatch::REASON_* slug that produced this. Kept for the same
		 * reason the id is: a suppression that turns out to be wrong has to be
		 * diagnosable from the ledger, and "why did it not save this?" cannot be
		 * answered by re-reading the sentence.
		 */
		public readonly string $reason,
		/**
		 * True when the incoming booking was *not* written because of this
		 * match. False means it was stored and merely flagged as a possible
		 * duplicate — the evidence pointed both ways, and losing a real booking
		 * is the worse of the two mistakes.
		 */
		public readonly bool $suppressed,
	) {
	}
}
