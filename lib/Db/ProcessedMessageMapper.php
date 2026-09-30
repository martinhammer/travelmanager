<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ProcessedMessage>
 */
class ProcessedMessageMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'travelmanager_messages', ProcessedMessage::class);
	}

	/**
	 * Dedup check (V6): has this user already processed this RFC Message-ID?
	 */
	public function isProcessed(string $userId, string $messageId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetch() !== false;
		$result->closeCursor();
		return $found;
	}

	/**
	 * Forget every processed-message record for a user so the same mailbox
	 * messages will be reprocessed on the next run (developer wipe).
	 */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function find(int $id, string $userId): ProcessedMessage {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * Newest first, by when the message arrived in the mailbox where known —
	 * ingestion order is a poor proxy once a backlog is read in one run.
	 *
	 * @return ProcessedMessage[]
	 */
	public function findAllForUser(string $userId, ?string $status = null, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('sent_at', 'DESC')
			->addOrderBy('processed_at', 'DESC')
			->setMaxResults($limit);
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Rows in a status across **all** users. The extraction limits are
	 * instance-wide, so this is deliberately not partitioned by user — the one
	 * place in this mapper that is not.
	 */
	public function countByStatus(string $status): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function countForUserByStatus(string $userId, string $status): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * The users with at least one row in a status — who has queued work.
	 *
	 * @return list<string>
	 */
	public function findUserIdsWithStatus(string $status): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		$result = $qb->executeQuery();
		/** @var list<array{user_id: string|int}> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): string => (string)$row['user_id'], $rows);
	}

	/**
	 * A user's row ids in a status, **oldest first by id** — insertion order,
	 * which within one mailbox read is UID order. That is the order the queue
	 * releases them in, and booking deduplication depends on it (CLAUDE.md §7).
	 *
	 * @return list<int>
	 */
	public function findIdsForUserByStatus(string $userId, string $status, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		/** @var list<array{id: string|int}> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}

	/**
	 * Move one row from queued to processing, **only if it is still queued**.
	 *
	 * The conditional update is what makes the claim safe when two pumps run at
	 * once (a cron tick and a task-completion listener, say): exactly one of them
	 * sees an affected row, so a message is never sent to the model twice.
	 * `processed_at` becomes the moment it was sent — the "Last processed" column.
	 */
	public function claimQueued(int $id, string $userId, \DateTime $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(ProcessedMessage::STATUS_PROCESSING))
			->set('processed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ProcessedMessage::STATUS_QUEUED)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Rows in a status across all users, oldest first. For housekeeping over the
	 * in-flight set, which the cap keeps small.
	 *
	 * @return ProcessedMessage[]
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

	public function findByMessageId(string $userId, string $messageId): ?ProcessedMessage {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
