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
 * Turn "may duplicate that one" into "belongs to this group of maybe-duplicates".
 *
 * `possible_duplicate_of` was a directed edge, and a duplicate relation is not
 * directed: it is an equivalence. With two bookings the difference is invisible;
 * with three it stops working, and three emails about one booking is ordinary.
 * Every booking pointed at the oldest, so the shape was a star, and:
 *
 * - **Display was not transitive.** The hub saw both of the others; each spoke
 *   saw only the hub. Which booking was the hub is an accident of arrival order.
 * - **Dismissal depended on where you pressed it.** Clearing every edge touching
 *   a spoke was right; doing it on the hub dissolved the whole cluster, throwing
 *   away a relationship between two bookings nobody had adjudicated.
 *
 * A group id fixes both by construction: every member sees every other member,
 * and one member leaving does not disturb the rest. **The group id is the id of
 * the oldest booking in the group**, so no sequence or side table is needed, and
 * the value stays meaningful even if that booking is later purged.
 *
 * The old column is migrated, not discarded — the edges are live flags in a
 * running instance, and a star converts exactly: every member's group is the
 * anchor's id, including the anchor's own. Dropping the old column is the next
 * migration, since a column cannot be read after the schema step that removes it.
 */
class Version2200Date20260902000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_bookings')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_bookings');

		if (!$table->hasColumn('duplicate_group_id')) {
			$table->addColumn('duplicate_group_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$table->hasIndex('tm_book_user_dupgroup')) {
			$table->addIndex(['user_id', 'duplicate_group_id'], 'tm_book_user_dupgroup');
		}

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('travelmanager_bookings')
			|| !$schema->getTable('travelmanager_bookings')->hasColumn('possible_duplicate_of')) {
			return;
		}

		// Each spoke joins the group named after the booking it pointed at.
		$qb = $this->db->getQueryBuilder();
		$qb->update('travelmanager_bookings')
			->set('duplicate_group_id', 'possible_duplicate_of')
			->where($qb->expr()->isNotNull('possible_duplicate_of'));
		$moved = $qb->executeStatement();

		// Then the anchors themselves, which carried no edge of their own. Read
		// the ids first rather than using a subquery over the table being
		// written: MySQL refuses that outright.
		$read = $this->db->getQueryBuilder();
		$read->selectDistinct('possible_duplicate_of')
			->from('travelmanager_bookings')
			->where($read->expr()->isNotNull('possible_duplicate_of'));
		$result = $read->executeQuery();
		/** @var list<int> $anchors */
		$anchors = array_map(static fn (mixed $id): int => (int)$id, $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		if ($anchors !== []) {
			$write = $this->db->getQueryBuilder();
			$write->update('travelmanager_bookings')
				->set('duplicate_group_id', 'id')
				->where($write->expr()->in('id', $write->createNamedParameter($anchors, IQueryBuilder::PARAM_INT_ARRAY)));
			$write->executeStatement();
		}

		$output->info('Travel Manager: grouped ' . $moved . ' possible duplicate(s) into ' . count($anchors) . ' group(s)');
	}
}
