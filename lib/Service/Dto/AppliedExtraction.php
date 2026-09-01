<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * The outcome of persisting one message's extracted bookings.
 *
 * In the MVP a message maps to a booking exactly once: the first email about a
 * booking creates it, and any later email BookingMatcher recognises as the same
 * booking is *reported* rather than applied. Updating an existing booking from
 * a second email is deliberately out of scope — a later email is a full replacement, not
 * a patch, so applying one would silently erase fields it happens not to repeat.
 * Until that is designed properly, the user is told and nothing is overwritten.
 */
class AppliedExtraction {
	/**
	 * @param int $created bookings newly stored from this message
	 * @param list<RelatedBooking> $related one entry per booking this message
	 *                                      relates to — whether it was suppressed
	 *                                      as a duplicate or stored and flagged as
	 *                                      a possible one
	 */
	public function __construct(
		public readonly int $created,
		public readonly array $related = [],
	) {
	}

	/**
	 * One line per related booking, for the activity log and the message row.
	 *
	 * Slug-prefixed like ExtractionResult::describeIssues(), for the same
	 * reason: the prose explains, but the slug is what you grep the ledger for
	 * when a suppression turns out to have been wrong.
	 */
	public function describeRelated(): string {
		return implode("\n", array_map(
			static fn (RelatedBooking $entry): string => '- [' . $entry->reason . '] ' . $entry->description,
			$this->related,
		));
	}

	/** How many of this message's bookings were not written because of a match. */
	public function suppressedCount(): int {
		return count(array_filter(
			$this->related,
			static fn (RelatedBooking $entry): bool => $entry->suppressed,
		));
	}

	/**
	 * The ids of the bookings this message is *about* without having created
	 * them, for storing on the message so the UI can offer to open them.
	 *
	 * **Suppressed matches only.** A possible duplicate is a relation between two
	 * bookings, not between an email and a booking, and it lives on
	 * `bookings.possible_duplicate_of` instead — otherwise this column would mean
	 * two different things at once and no label could be right for both.
	 *
	 * @return list<int>
	 */
	public function relatedBookingIds(): array {
		return array_values(array_unique(array_map(
			static fn (RelatedBooking $entry): int => $entry->bookingId,
			array_filter($this->related, static fn (RelatedBooking $entry): bool => $entry->suppressed),
		)));
	}
}
