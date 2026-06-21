<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

/**
 * Connection parameters for a user's dedicated travel mailbox. The password is
 * passed transiently here (retrieved from ICredentialsManager by the caller)
 * and never persisted by the IMAP layer.
 */
class ImapConnection {
	public function __construct(
		public readonly string $host,
		public readonly int $port,
		/** one of: ssl, tls, none */
		public readonly string $security,
		public readonly string $user,
		public readonly string $password,
		public readonly string $mailbox,
	) {
	}
}
