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
 * Split the booking's single `status` column into two orthogonal axes.
 *
 * `status` keeps the *booking fact* (active / cancelled / superseded — what the
 * provider did), while the new `review_state` holds the *user's decision*
 * (draft / confirmed / discarded / archived). Flattened into one column these
 * were mutually exclusive: a cancelled booking could not also be one the user
 * had reviewed, and discarding meant hard-deleting the row.
 *
 * Existing rows are remapped: 'confirmed' becomes review_state=confirmed, and
 * the review values ('draft'/'confirmed') collapse to status='active'.
 */
class Version1300Date20260814000000 extends SimpleMigrationStep {
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

		if (!$table->hasColumn('review_state')) {
			$table->addColumn('review_state', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'draft']);
		}
		if (!$table->hasIndex('tm_book_user_review')) {
			$table->addIndex(['user_id', 'review_state'], 'tm_book_user_review');
		}
		// New bookings are a fact about the world first; the user reviews them
		// separately via review_state.
		$table->getColumn('status')->setDefault('active');

		return $schema;
	}

	/**
	 * Backfill the split from the pre-existing single-column values. Ordering
	 * matters: derive review_state from the old status before collapsing the
	 * review values in status itself.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('travelmanager_bookings')
			->set('review_state', $qb->createNamedParameter('confirmed'))
			->where($qb->expr()->eq('status', $qb->createNamedParameter('confirmed')));
		$qb->executeStatement();

		// 'draft'/'confirmed' were review decisions, not booking facts.
		$qb = $this->db->getQueryBuilder();
		$qb->update('travelmanager_bookings')
			->set('status', $qb->createNamedParameter('active'))
			->where($qb->expr()->in('status', $qb->createNamedParameter(['draft', 'confirmed'], IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}
}
