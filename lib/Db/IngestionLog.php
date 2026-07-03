<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One line in the per-user ingestion activity log (developer/debugging aid).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getLevel()
 * @method void setLevel(string $level)
 * @method string getStep()
 * @method void setStep(string $step)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method string|null getContext()
 * @method void setContext(?string $context)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 *
 * @psalm-import-type TravelManagerLog from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class IngestionLog extends Entity implements \JsonSerializable {
	public const LEVEL_INFO = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR = 'error';

	// Pipeline steps, in rough order of occurrence.
	public const STEP_CONNECT = 'connect';
	public const STEP_FETCH = 'fetch';
	public const STEP_DEDUP = 'dedup';
	public const STEP_SCHEDULE = 'schedule';
	public const STEP_LLM_RESPONSE = 'llm_response';
	public const STEP_PERSIST = 'persist';
	public const STEP_WIPE = 'wipe';

	protected string $userId = '';
	protected string $level = self::LEVEL_INFO;
	protected string $step = '';
	protected string $message = '';
	protected ?string $context = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * @return TravelManagerLog
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'level' => $this->level,
			'step' => $this->step,
			'message' => $this->message,
			'context' => $this->context,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
