<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-type booking schema.
 *
 * The single shared travelmanager_segments table is dropped; all type-specific
 * structure (flight legs + passengers, car supplier/features, hotel stay, …)
 * now lives in a per-type JSON `details` column on the booking, validated in
 * ExtractionService. The booking header keeps only stable, cross-type fields
 * plus a denormalised start/end span for list ordering, and gains a
 * confirmation_number distinct from the booking_reference.
 */
class Version1200Date20260706000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('travelmanager_segments')) {
			$schema->dropTable('travelmanager_segments');
		}

		if ($schema->hasTable('travelmanager_bookings')) {
			$table = $schema->getTable('travelmanager_bookings');

			if (!$table->hasColumn('confirmation_number')) {
				$table->addColumn('confirmation_number', Types::STRING, ['notnull' => false, 'length' => 255]);
			}
			if (!$table->hasColumn('start_date')) {
				$table->addColumn('start_date', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('end_date')) {
				$table->addColumn('end_date', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('details')) {
				$table->addColumn('details', Types::TEXT, ['notnull' => false]);
			}
			if ($table->hasColumn('extraction_json')) {
				$table->dropColumn('extraction_json');
			}
		}

		return $schema;
	}
}
