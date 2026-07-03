<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
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
