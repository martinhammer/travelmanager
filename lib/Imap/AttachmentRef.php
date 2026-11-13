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
		/**
		 * Decoded size, estimated: Horde scales a base64 part's BODYSTRUCTURE
		 * size by 3/4 when it parses it, so this is close to the file's own size.
		 */
		public readonly int $bytes,
	) {
	}
}
