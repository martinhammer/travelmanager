<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Booking>
 */
class BookingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'travelmanager_bookings', Booking::class);
	}

	public function find(int $id, string $userId): Booking {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Booking[]
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * @return Booking[]
	 */
	public function findByStatus(string $userId, string $status): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * @return Booking[]
	 */
	public function findByTrip(string $userId, int $tripId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('trip_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Delete every booking belonging to a user (developer wipe / reprocess).
	 */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Find an existing booking to update/cancel based on its natural key
	 * (type + provider + reference). Used for update/cancellation idempotency (V6).
	 */
	public function findByReference(string $userId, string $type, ?string $provider, ?string $reference): ?Booking {
		if ($reference === null || $reference === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('booking_reference', $qb->createNamedParameter($reference)));
		if ($provider === null || $provider === '') {
			$qb->andWhere($qb->expr()->isNull('provider'));
		} else {
			$qb->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)));
		}
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}
}
