<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use OCA\TravelManager\Db\ColumnText;

/**
 * The dedup key for one email: its RFC Message-ID whenever that can be stored
 * as it is, and a stable stand-in when it cannot.
 *
 * Never cut to fit. The key is what makes a later run recognise the email, and
 * two long Message-IDs sharing their first 255 characters would then be one
 * email, so the second would never be read. A hash of the whole value is
 * just as stable and cannot collide that way.
 */
final class MessageId {
	public static function key(string $raw, int $uidValidity, int $uid): string {
		$id = trim($raw);
		if ($id === '') {
			// No Message-ID at all: as stable as the mailbox's UIDs are.
			return sprintf('<tm-%d-%d@travelmanager.local>', $uidValidity, $uid);
		}
		if (!mb_check_encoding($id, 'UTF-8') || str_contains($id, "\0") || mb_strlen($id, 'UTF-8') > ColumnText::VARCHAR) {
			return '<tm-sha1-' . sha1($id) . '@travelmanager.local>';
		}
		return $id;
	}
}
