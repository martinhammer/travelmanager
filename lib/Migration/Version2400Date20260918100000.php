<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `messages.dismissed`, now that its values have been carried over to
 * `discarded`.
 *
 * A separate migration from the copy for the same reason the duplicate-group
 * pair was split: the schema step runs before the data step, so one class cannot
 * both read the old column and remove it. Ordering is by class name, and this
 * sorts after Version2400Date20260918000000.
 *
 * Dropping it is not optional tidying. `findAllForUser` selects `*` and
 * `findEntities` calls a setter per column, so a column with no matching entity
 * property makes every message load throw.
 */
class Version2400Date20260918100000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if ($table->hasColumn('dismissed')) {
			$table->dropColumn('dismissed');
		}

		return $schema;
	}
}
