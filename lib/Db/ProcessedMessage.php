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
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 * @method string|null getBodyText()
 * @method void setBodyText(?string $bodyText)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method string|null getFailureKind()
 * @method void setFailureKind(?string $failureKind)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method string|null getLastResponse()
 * @method void setLastResponse(?string $lastResponse)
 * @method \DateTime|null getProcessedAt()
 * @method void setProcessedAt(?\DateTime $processedAt)
 *
 * @psalm-import-type TravelManagerMessage from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class ProcessedMessage extends Entity implements \JsonSerializable {
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_PROCESSED = 'processed';
	public const STATUS_FAILED = 'failed';
	/** The model reported no booking in this email — nothing to retry. */
	public const STATUS_NO_BOOKING = 'no_booking';
	/**
	 * The model *did* report booking(s), but validation refused every one of
	 * them (usually an unparseable anchoring date). Unlike no_booking this is
	 * worth retrying or tuning the prompt against.
	 */
	public const STATUS_DROPPED = 'dropped';
	/**
	 * Every booking in this email matched one the user already has. The MVP
	 * creates a booking from one message only and never updates it from a
	 * later one, so this is reported rather than applied.
	 */
	public const STATUS_RELATED = 'related';

	/**
	 * Why the last attempt failed. Retrying a provider/transport failure
	 * verbatim usually works; retrying a validation failure is a coin flip, and
	 * the real fix is normally the prompt.
	 */
	public const FAILURE_SCHEDULE = 'schedule';
	public const FAILURE_PROVIDER = 'provider';
	public const FAILURE_VALIDATION = 'validation';

	protected string $userId = '';
	protected string $mailbox = '';
	protected string $messageId = '';
	protected ?int $uidValidity = null;
	protected ?int $imapUid = null;
	protected string $status = self::STATUS_PROCESSED;
	protected ?string $subject = null;
	protected ?\DateTime $sentAt = null;
	/** Plain-text body as fed to the model; retained so extraction can be re-run. */
	protected ?string $bodyText = null;
	protected int $attempts = 0;
	protected ?string $failureKind = null;
	protected ?string $error = null;
	/** Raw model output from the last attempt, truncated — the thing you read to diagnose a failure. */
	protected ?string $lastResponse = null;
	protected ?\DateTime $processedAt = null;

	public function __construct() {
		$this->addType('uidValidity', 'integer');
		$this->addType('imapUid', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('sentAt', 'datetime');
		$this->addType('processedAt', 'datetime');
	}

	/** A retry needs the source text; without it the only route back is IMAP. */
	public function canRetry(): bool {
		return $this->bodyText !== null && $this->bodyText !== '';
	}

	/**
	 * @return TravelManagerMessage
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'mailbox' => $this->mailbox,
			'messageId' => $this->messageId,
			'subject' => $this->subject,
			'status' => $this->status,
			'failureKind' => $this->failureKind,
			'error' => $this->error,
			'lastResponse' => $this->lastResponse,
			'attempts' => $this->attempts,
			// The body itself is deliberately not serialised — it is bulky and
			// the list view only needs to know whether a retry is possible.
			'canRetry' => $this->canRetry(),
			'sentAt' => $this->sentAt?->format(\DateTimeInterface::ATOM),
			'processedAt' => $this->processedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
