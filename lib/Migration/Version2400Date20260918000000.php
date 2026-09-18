<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename `messages.dismissed` to `messages.discarded` — add and copy here, drop
 * in the next migration.
 *
 * The column shipped for a few hours as `dismissed` and was renamed the same day,
 * because Discard/Restore is the pair a *booking* already uses for this idea and
 * the schema should agree with the word on the button. `Version2300` cannot just
 * be edited to say `discarded`: an instance that already ran it has
 * `installed_version` at 1.17.0 and will never run it again, so it keeps a
 * `dismissed` column that nothing writes and a missing `discarded` one.
 *
 * That combination is not merely untidy, it is fatal to reads:
 * `ProcessedMessageMapper::findAllForUser` selects `*` and `findEntities` calls a
 * setter per column, so a stale `dismissed` column makes every message load throw
 * `BadFunctionCallException` and `GET /api/messages` return 500 — which is
 * exactly how this was found.
 *
 * No-ops on an instance that never ran the 1.17.0 build: `discarded` is already
 * there from `Version2300`, and there is no `dismissed` column to copy.
 */
class Version2400Date20260918000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('discarded')) {
			$table->addColumn('discarded', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		}

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('travelmanager_messages')
			|| !$schema->getTable('travelmanager_messages')->hasColumn('dismissed')) {
			return;
		}

		// Carry the decisions over rather than starting from false: someone may
		// have spent the morning settling rows, and silently un-settling them
		// would put failures nobody can act on back in the attention counter.
		$qb = $this->db->getQueryBuilder();
		$qb->update('travelmanager_messages')
			->set('discarded', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('dismissed', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		$carried = $qb->executeStatement();

		$output->info('Travel Manager: carried ' . $carried . ' dismissed message(s) over to discarded');
	}
}
