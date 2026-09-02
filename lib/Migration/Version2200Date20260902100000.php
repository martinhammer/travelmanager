<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `bookings.possible_duplicate_of`, now that its edges have been converted
 * into groups.
 *
 * A separate migration from the conversion on purpose: the schema step runs
 * before the data step, so a single class cannot both read the old column and
 * remove it. Ordering is by class name, and this one sorts after
 * Version2200Date20260902000000.
 */
class Version2200Date20260902100000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_bookings')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_bookings');

		if ($table->hasIndex('tm_book_user_dup')) {
			$table->dropIndex('tm_book_user_dup');
		}
		if ($table->hasColumn('possible_duplicate_of')) {
			$table->dropColumn('possible_duplicate_of');
		}

		return $schema;
	}
}
