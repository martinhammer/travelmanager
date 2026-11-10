<?php

declare(strict_types=1);

namespace OCA\TravelManager\Exception;

/**
 * Thrown when a PDF attachment cannot be read: not a PDF, malformed, or
 * encrypted with a password we do not have.
 *
 * Encryption is told apart because it is the one failure the user can act on
 * (the sender's PDF needs a password) rather than a defect, so the Messages
 * view words it differently.
 */
class PdfException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly bool $encrypted = false,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
