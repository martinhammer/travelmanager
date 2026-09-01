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
	 * Fetch the most recent messages from the configured mailbox, **oldest first**.
	 *
	 * Two separate things: *which* messages (the newest $limit, since a mailbox
	 * can hold years of mail) and *what order* they come back in (oldest first,
	 * so they reach the model in the order they arrived).
	 *
	 * The order is part of the contract, not an implementation detail. Booking
	 * deduplication treats the first email about a booking as the one that
	 * creates it and every later one as being about that booking; hand them over
	 * newest-first and "first" silently means "whichever extraction task finished
	 * first", which is arbitrary. Note this only orders the *scheduling* —
	 * extraction is asynchronous, so with several AI workers running the results
	 * can still arrive out of order.
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
