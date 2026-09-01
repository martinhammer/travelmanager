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

	/**
	 * Drop every edge pointing at this booking as a possible duplicate.
	 *
	 * `possible_duplicate_of` is stored one way round but read both ways, so
	 * clearing the pointing booking is the only way to clear the flag from the
	 * pointed-at one's card. Also runs before a hard delete: the column is not a
	 * foreign key, so nothing else would tidy up after a purge.
	 */
	public function clearPossibleDuplicatesOf(string $userId, int $bookingId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('possible_duplicate_of', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('possible_duplicate_of', $qb->createNamedParameter($bookingId, IQueryBuilder::PARAM_INT)));
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
