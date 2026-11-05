<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One row of the backup that was not written, and why. Reported to the user, so
 * `existingId` is there to link the row it gave way to.
 */
class ImportSkip {
	public const KIND_TRIP = 'trip';
	public const KIND_BOOKING = 'booking';
	public const KIND_MESSAGE = 'message';

	/** A trip of the same name exists, and the imported bookings joined it. */
	public const REASON_TRIP_EXISTS = 'trip_exists';
	/** A booking of the same type from the same email exists. */
	public const REASON_BOOKING_EXISTS = 'booking_exists';
	/** This email is already in the ledger. */
	public const REASON_MESSAGE_EXISTS = 'message_exists';
	/**
	 * It was waiting for the model, and the backup left its text out. Importing it
	 * would mark an email as handled that nothing can ever extract, whereas left
	 * out, the next mailbox read picks it up afresh.
	 */
	public const REASON_UNFINISHED_NO_TEXT = 'unfinished_no_text';

	public function __construct(
		public readonly string $kind,
		public readonly string $reason,
		public readonly string $label,
		public readonly ?int $existingId,
	) {
	}
}
