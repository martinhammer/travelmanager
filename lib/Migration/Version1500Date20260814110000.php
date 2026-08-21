<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Keep the raw model response on the message itself.
 *
 * It was previously only written to the activity log's context — dev tooling,
 * capped at 1000 rows per user — so the Messages view could show *that* an
 * extraction failed but not *what came back*, which is the half you actually
 * need to diagnose it. Truncated on write; dropped alongside `body_text` when
 * the message's bookings are archived.
 */
class Version1500Date20260814110000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('last_response')) {
			$table->addColumn('last_response', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
