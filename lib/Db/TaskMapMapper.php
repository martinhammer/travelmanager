<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TaskMap>
 */
class TaskMapMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'travelmanager_tasks', TaskMap::class);
	}

	/**
	 * Correlate a completed Task Processing task back to its message/user (V5).
	 */
	public function findByTaskId(int $taskId): ?TaskMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The most recent task scheduled for a message — the attempt whose outcome
	 * the message row reflects. An older pending row for the same message is a
	 * superseded attempt and must not be allowed to overwrite it.
	 */
	public function findLatestForMessage(string $userId, string $messageId): ?TaskMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * How many tasks we started since a moment, across all users — the hourly
	 * budget's measure. Every schedule writes a row here (first attempts and
	 * retries alike, and a retry *is* a request), so this needs no counter of its
	 * own to keep in step, and it survives restarts.
	 */
	public function countCreatedSince(\DateTime $since): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Correlation rows in a status across all users, oldest first — for checking
	 * pending tasks against what Task Processing says about them.
	 *
	 * @return TaskMap[]
	 */
	public function findByStatus(string $status, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Delete every task correlation row for a user (developer wipe / reprocess).
	 */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
