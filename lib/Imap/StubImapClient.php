<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use OCA\TravelManager\Exception\ImapException;

/**
 * Placeholder IMAP client used while the pipeline is scaffolded. It performs
 * NO network I/O — the real Horde-backed client is wired in the ingestion
 * implementation step. Bound as the default {@see IImapClient} so that
 * accidentally enabling the feature before then fails loudly and safely
 * (the per-user job catches this and records it, without crashing the run).
 */
class StubImapClient implements IImapClient {
	public function fetchRecent(ImapConnection $connection, int $limit): array {
		throw new ImapException('IMAP ingestion is not yet implemented (scaffold stub)');
	}

	public function verify(ImapConnection $connection): void {
		throw new ImapException('IMAP ingestion is not yet implemented (scaffold stub)');
	}
}
