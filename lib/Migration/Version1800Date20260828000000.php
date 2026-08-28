<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Record which bookings a `related` message matched.
 *
 * The relationship already existed, but only inside a sentence in
 * `messages.error` ("matches the existing booking #12"), so the Messages view
 * could say a booking was matched without being able to open it — the one case
 * where the user most wants that jump. Exactly the problem `issue_reasons`
 * solved for extraction issues.
 *
 * Comma-separated ids rather than a join table or a single foreign key: one
 * email can match more than one existing booking (a flight *and* its hotel), the
 * set is tiny and unordered, and it is read only for display.
 *
 * No backfill — the old rows' ids are only recoverable by parsing prose, and a
 * re-run of the message produces the column properly.
 */
class Version1800Date20260828000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('related_booking_ids')) {
			$table->addColumn('related_booking_ids', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		return $schema;
	}
}
