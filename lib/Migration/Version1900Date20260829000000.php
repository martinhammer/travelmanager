<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Give a trip a type (work / leisure) and a colour.
 *
 * Both are **user-entered and nullable**, and neither is backfilled: nothing in
 * an extracted booking says whether a trip was for work, and guessing would put
 * a confident lozenge on a trip nobody has classified. A trip without a type
 * simply shows none.
 *
 * `type` is a slug in a short string rather than an enum or a lookup table: the
 * set is expected to grow (work/leisure is explicitly a starting point), and a
 * value the code does not recognise has to survive a round trip rather than
 * break a query. Validation lives in BookingService, where the allowed set is
 * one constant.
 *
 * `color` is a 7-character `#rrggbb`, the form NcColorPicker emits and CSS
 * consumes, so it is stored exactly as it is used and never reformatted.
 */
class Version1900Date20260829000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_trips')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_trips');

		if (!$table->hasColumn('type')) {
			$table->addColumn('type', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
		}

		if (!$table->hasColumn('color')) {
			$table->addColumn('color', Types::STRING, [
				'notnull' => false,
				'length' => 7,
			]);
		}

		return $schema;
	}
}
