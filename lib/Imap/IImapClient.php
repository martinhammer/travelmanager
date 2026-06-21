<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use OCA\TravelManager\Exception\ImapException;

/**
 * Read-only IMAP consumer for a user's dedicated travel mailbox.
 *
 * The app never modifies the mailbox (no flags, no moves — see V6); dedup is
 * tracked entirely in the app database. Implementations are independent of the
 * Mail app and bundle their own (back-end only) IMAP protocol library (V3).
 */
interface IImapClient {
	/**
	 * Fetch the most recent messages from the configured mailbox, newest first.
	 *
	 * @param int $limit maximum number of messages to return
	 * @return ImapMessage[]
	 * @throws ImapException on connection/auth/protocol failure
	 */
	public function fetchRecent(ImapConnection $connection, int $limit): array;

	/**
	 * Verify that the given connection can authenticate and open the mailbox.
	 *
	 * @throws ImapException when the mailbox cannot be opened
	 */
	public function verify(ImapConnection $connection): void;
}
