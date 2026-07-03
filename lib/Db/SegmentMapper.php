<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Segment>
 */
class SegmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'travelmanager_segments', Segment::class);
	}

	/**
	 * @return Segment[]
	 */
	public function findForBooking(string $userId, int $bookingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->orderBy('sequence', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForBooking(string $userId, int $bookingId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('booking_id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Delete every segment belonging to a user (developer wipe / reprocess).
	 */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
