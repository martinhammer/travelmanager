<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the per-user ingestion activity log (travelmanager_logs).
 *
 * A developer/debugging aid: records each step of the pipeline (connecting to
 * the mailbox, listing messages, scheduling extraction, LLM response, persisting
 * bookings) so the run can be inspected from the UI without tailing the server
 * log. Partitioned by user_id like every other table.
 */
class Version1000Date20260703000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_logs')) {
			$table = $schema->createTable('travelmanager_logs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'info']);
			$table->addColumn('step', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('message', Types::TEXT, ['notnull' => true, 'default' => '']);
			$table->addColumn('context', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'id'], 'tm_log_user_id');
		}

		return $schema;
	}
}
