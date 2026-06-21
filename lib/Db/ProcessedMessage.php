<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getMailbox()
 * @method void setMailbox(string $mailbox)
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method int|null getUidValidity()
 * @method void setUidValidity(?int $uidValidity)
 * @method int|null getImapUid()
 * @method void setImapUid(?int $imapUid)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method \DateTime|null getProcessedAt()
 * @method void setProcessedAt(?\DateTime $processedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class ProcessedMessage extends Entity implements \JsonSerializable {
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_PROCESSED = 'processed';
	public const STATUS_FAILED = 'failed';
	public const STATUS_NO_BOOKING = 'no_booking';

	protected string $userId = '';
	protected string $mailbox = '';
	protected string $messageId = '';
	protected ?int $uidValidity = null;
	protected ?int $imapUid = null;
	protected string $status = self::STATUS_PROCESSED;
	protected ?string $error = null;
	protected ?\DateTime $processedAt = null;

	public function __construct() {
		$this->addType('uidValidity', 'integer');
		$this->addType('imapUid', 'integer');
		$this->addType('processedAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'mailbox' => $this->mailbox,
			'messageId' => $this->messageId,
			'status' => $this->status,
			'error' => $this->error,
			'processedAt' => $this->processedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
