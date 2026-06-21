<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getTaskId()
 * @method void setTaskId(int $taskId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class TaskMap extends Entity {
	public const STATUS_PENDING = 'pending';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED = 'failed';

	protected int $taskId = 0;
	protected string $userId = '';
	protected string $messageId = '';
	protected string $status = self::STATUS_PENDING;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('taskId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
