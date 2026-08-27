<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Record *which* extraction issues a message hit, as slugs.
 *
 * The issues were already written to `error` as prose ("- [repaired_json] …"),
 * which is fine to read but not to branch on: the Messages view needs to know
 * whether a response was repaired in order to show a notice for it, and parsing
 * our own sentences back out on the client would make that wording a wire
 * contract. Slugs keep the machine-readable and human-readable halves apart.
 *
 * Comma-separated rather than a join table: it is a short, unordered set read
 * only for display, never queried.
 */
class Version1700Date20260827100000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('issue_reasons')) {
			$table->addColumn('issue_reasons', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		return $schema;
	}
}
