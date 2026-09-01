<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Move "these two bookings may be the same" onto the bookings themselves.
 *
 * BookingMatcher can find a resemblance it is not sure enough of to act on, and
 * stores the booking anyway rather than suppress it. That fact was recorded in
 * `messages.related_booking_ids`, which was the wrong home for it twice over:
 *
 * - **It is a booking↔booking relation**, not a message one. The email is only
 *   where it was noticed. Kept on the message, it could only be shown in the
 *   Messages view — while the thing the user has to act on is a pair of
 *   bookings, and the Bookings view could not see it at all.
 * - **It made that column mean two things**: "every booking in this email
 *   already existed" (the `related` status — genuinely a message-level fact) and
 *   "this email made a booking that resembles another". One column, two claims,
 *   so no label could be right for both. The column now means only the first.
 *
 * Nullable and one-directional in storage, symmetric in use: the reverse edge is
 * derived client-side (`possibleDuplicates` in `src/bookings.ts`), because both
 * bookings are equally real once they exist and which one arrived first is not
 * something the user should have to know to find the flag.
 *
 * Not a foreign key: the app deletes bookings outright (`BookingService::purge`)
 * and clears inbound edges there. No backfill — the ids in the old column are
 * indistinguishable from the `related` ones without re-running the extraction.
 */
class Version2100Date20260901000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_bookings')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_bookings');

		if (!$table->hasColumn('possible_duplicate_of')) {
			$table->addColumn('possible_duplicate_of', Types::BIGINT, ['notnull' => false]);
		}

		// Read in both directions on every booking card, so the inbound side
		// needs an index of its own.
		if (!$table->hasIndex('tm_book_user_dup')) {
			$table->addIndex(['user_id', 'possible_duplicate_of'], 'tm_book_user_dup');
		}

		return $schema;
	}
}
