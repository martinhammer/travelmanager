<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

/**
 * A PDF attachment found in a message's structure, before anything is
 * downloaded: enough to decide whether to fetch it, and to name it in the
 * record of what was read.
 */
final class AttachmentRef {
	public function __construct(
		/** MIME part id, as IImapClient::fetchParts takes it. */
		public readonly string $mimeId,
		/** The sender's filename, or null when the part names none. */
		public readonly ?string $filename,
		/** Size on the wire — transfer-encoded, so base64 runs a third over the file. */
		public readonly int $bytes,
	) {
	}
}
