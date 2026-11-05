<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One validated ledger row from a backup file. Dates are local wall-clock
 * `Y-m-d\TH:i:s` strings (no offset, V8).
 */
class BackupMessage {
	/**
	 * @param list<string> $issueReasons
	 * @param list<int> $relatedBookingIds ids on the exporting server
	 */
	public function __construct(
		public readonly string $mailbox,
		public readonly string $messageId,
		public readonly ?int $uidValidity,
		public readonly ?int $imapUid,
		public readonly string $status,
		public readonly ?string $subject,
		public readonly ?string $sender,
		public readonly ?string $sentAt,
		public readonly ?string $bodyText,
		public readonly int $attempts,
		public readonly ?string $failureKind,
		public readonly array $issueReasons,
		public readonly array $relatedBookingIds,
		public readonly ?string $error,
		public readonly ?string $lastResponse,
		public readonly ?string $processedAt,
		public readonly bool $discarded,
	) {
	}

	/**
	 * Waiting for the model when the backup was taken. Whatever task it was
	 * waiting on belongs to the exporting server, so its answer can never arrive
	 * here: such a row has to go round the queue again.
	 */
	public function isUnfinished(): bool {
		return $this->status === 'queued' || $this->status === 'processing';
	}

	/** Whether there is anything to send the model. */
	public function hasText(): bool {
		return $this->bodyText !== null && trim($this->bodyText) !== '';
	}

	public function label(): string {
		return $this->subject === null || $this->subject === '' ? $this->messageId : $this->subject;
	}
}
