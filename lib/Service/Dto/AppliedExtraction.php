<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * The outcome of persisting one message's extracted bookings.
 *
 * In the MVP a message maps to a booking exactly once: the first email about a
 * booking creates it, and any later email matching the same natural key is
 * *reported* rather than applied. Updating an existing booking from a second
 * email is deliberately out of scope — a later email is a full replacement, not
 * a patch, so applying one would silently erase fields it happens not to repeat.
 * Until that is designed properly, the user is told and nothing is overwritten.
 */
class AppliedExtraction {
	/**
	 * @param int $created bookings newly stored from this message
	 * @param list<string> $related one line per booking that matched an existing
	 *                              one and was therefore left alone
	 */
	public function __construct(
		public readonly int $created,
		public readonly array $related = [],
	) {
	}

	/** One line per skipped booking, for the activity log and the message row. */
	public function describeRelated(): string {
		return implode("\n", array_map(static fn (string $line): string => '- ' . $line, $this->related));
	}
}
