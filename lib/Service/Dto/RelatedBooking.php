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
	) {
	}
}
