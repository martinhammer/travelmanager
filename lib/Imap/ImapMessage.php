<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

/**
 * A fetched message reduced to what the extraction pipeline needs.
 */
class ImapMessage {
	public function __construct(
		/** RFC 5322 Message-ID header — the dedup key (V6). */
		public readonly string $messageId,
		public readonly int $uid,
		public readonly int $uidValidity,
		public readonly string $subject,
		public readonly ?\DateTimeImmutable $date,
		/** Plain-text body, HTML stripped, ready for the LLM. */
		public readonly string $textBody,
		/**
		 * The From address as displayed ("KLM <noreply@klm.com>"), or null when
		 * the envelope carried none. Display metadata only — never a dedup or
		 * classification input.
		 */
		public readonly ?string $from = null,
	) {
	}
}
