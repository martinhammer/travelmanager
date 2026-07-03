<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<IngestionLog>
 */
class IngestionLogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'travelmanager_logs', IngestionLog::class);
	}

	/**
	 * Most recent log lines for a user, newest first.
	 *
	 * @return IngestionLog[]
	 */
	public function findForUser(string $userId, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Cap the log at the newest $keep rows for a user so it can't grow without
	 * bound during repeated dev runs.
	 */
	public function pruneForUser(string $userId, int $keep = 1000): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC')
			->setFirstResult($keep)
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		if ($rows === []) {
			return;
		}
		$threshold = $rows[0]->getId();

		$del = $this->db->getQueryBuilder();
		$del->delete($this->getTableName())
			->where($del->expr()->eq('user_id', $del->createNamedParameter($userId)))
			->andWhere($del->expr()->lte('id', $del->createNamedParameter($threshold, IQueryBuilder::PARAM_INT)));
		$del->executeStatement();
	}
}
