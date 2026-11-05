<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/** What restoring a backup will write, and what it will leave alone. */
class ImportPlan {
	/**
	 * @param list<BackupTrip> $trips to create
	 * @param array<int, int> $tripMatches backup trip id => existing trip id
	 * @param list<BackupBooking> $bookings to create
	 * @param array<int, int> $bookingMatches backup booking id => existing booking id
	 * @param list<BackupMessage> $messages to create
	 * @param list<ImportSkip> $skipped
	 */
	public function __construct(
		public readonly array $trips,
		public readonly array $tripMatches,
		public readonly array $bookings,
		public readonly array $bookingMatches,
		public readonly array $messages,
		public readonly array $skipped,
	) {
	}

	/** How many of the messages to create will be sent to the model. */
	public function requeued(): int {
		return count(array_filter($this->messages, static fn (BackupMessage $m): bool => $m->isUnfinished()));
	}
}
