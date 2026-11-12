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
	public function findAllOldestFirst(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return Booking[]
	 */
	public function findByReviewState(string $userId, string $reviewState): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('review_state', $qb->createNamedParameter($reviewState)))
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

	/** Put one booking into a group of maybe-duplicates, or take it out (null). */
	public function setDuplicateGroup(string $userId, int $bookingId, ?int $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('duplicate_group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Up to $limit ids in a duplicate group — enough to tell "alone" from "still
	 * a group" without counting the lot.
	 *
	 * @return list<int>
	 */
	public function duplicateGroupMemberIds(string $userId, int $groupId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('duplicate_group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		/** @var list<array{id: string|int}> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}

	/**
	 * Take one booking out of a group, **only if it is still in that group**.
	 *
	 * The clear that dissolves a group of one names the member it found rather
	 * than the group: a booking that joined in the meantime is in the group too,
	 * and clearing by group would take its flag away with the lone one's.
	 */
	public function clearDuplicateGroupOf(string $userId, int $bookingId, int $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('duplicate_group_id', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('duplicate_group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Existing bookings worth comparing an incoming extraction against.
	 *
	 * Deliberately a *candidate* query, not the decision: whether two bookings
	 * are the same one is a judgement about identifiers, operator names and
	 * dates that belongs in BookingMatcher, where it is pure and testable. This
	 * only has to be a superset — cheap, indexed, and generous enough that the
	 * matcher never misses a duplicate because the row was not fetched.
	 *
	 * Two ways in, because either alone would miss real duplicates:
	 * - **the date window**, which catches a second email that labels the
	 *   identifiers differently (or carries none at all);
	 * - **a literal identifier hit**, which catches a change or rebooking email
	 *   that moved the dates clean out of the window.
	 *
	 * @param list<string> $identifiers reference/confirmation values to look for,
	 *                                  raw as the model produced them
	 * @return Booking[]
	 */
	public function findMatchCandidates(
		string $userId,
		string $type,
		?\DateTime $startDate,
		int $windowDays,
		array $identifiers,
	): array {
		$qb = $this->db->getQueryBuilder();
		$alternatives = [];

		if ($startDate !== null) {
			$from = (clone $startDate)->modify('-' . $windowDays . ' days');
			$to = (clone $startDate)->modify('+' . $windowDays . ' days');
			$alternatives[] = $qb->expr()->andX(
				$qb->expr()->gte('start_date', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
				$qb->expr()->lte('start_date', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
			);
		}

		if ($identifiers !== []) {
			$list = $qb->createNamedParameter($identifiers, IQueryBuilder::PARAM_STR_ARRAY);
			$alternatives[] = $qb->expr()->in('booking_reference', $list);
			$alternatives[] = $qb->expr()->in('confirmation_number', $list);
		}

		if ($alternatives === []) {
			return [];
		}

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->orX(...$alternatives))
			// Oldest first: the email that created the booking wins ties, which
			// is the same rule one-message-one-booking already follows.
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
