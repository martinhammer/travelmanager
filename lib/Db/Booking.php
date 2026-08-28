<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int|null getTripId()
 * @method void setTripId(?int $tripId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getProvider()
 * @method void setProvider(?string $provider)
 * @method string|null getBookingReference()
 * @method void setBookingReference(?string $bookingReference)
 * @method string|null getConfirmationNumber()
 * @method void setConfirmationNumber(?string $confirmationNumber)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getReviewState()
 * @method void setReviewState(string $reviewState)
 * @method string|null getSourceMessageId()
 * @method void setSourceMessageId(?string $sourceMessageId)
 * @method float|null getConfidence()
 * @method void setConfidence(?float $confidence)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method \DateTime|null getStartDate()
 * @method void setStartDate(?\DateTime $startDate)
 * @method \DateTime|null getEndDate()
 * @method void setEndDate(?\DateTime $endDate)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method \DateTime|null getConfirmedAt()
 * @method void setConfirmedAt(?\DateTime $confirmedAt)
 *
 * @psalm-import-type TravelManagerBooking from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Booking extends Entity implements \JsonSerializable {
	/**
	 * `status` is a fact about the booking itself — what the provider did.
	 * It is set from the extracted email and is never a user decision.
	 */
	public const STATUS_ACTIVE = 'active';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_SUPERSEDED = 'superseded';

	/**
	 * `review_state` is the user's decision about the booking, orthogonal to
	 * `status` (a cancelled booking can still be confirmed and later archived).
	 * Discarded and archived are soft states: the row is kept so re-extraction
	 * cannot resurrect it as a fresh draft, and so the user can undo.
	 */
	public const REVIEW_DRAFT = 'draft';
	public const REVIEW_CONFIRMED = 'confirmed';
	public const REVIEW_DISCARDED = 'discarded';
	public const REVIEW_ARCHIVED = 'archived';

	/** Every state a client may move a booking into. */
	public const REVIEW_STATES = [
		self::REVIEW_DRAFT,
		self::REVIEW_CONFIRMED,
		self::REVIEW_DISCARDED,
		self::REVIEW_ARCHIVED,
	];

	public const TYPE_FLIGHT = 'flight';
	public const TYPE_ACCOMMODATION = 'accommodation';
	public const TYPE_CAR_RENTAL = 'car_rental';

	protected string $userId = '';
	protected ?int $tripId = null;
	protected string $type = '';
	protected ?string $provider = null;
	protected ?string $bookingReference = null;
	protected ?string $confirmationNumber = null;
	protected ?string $title = null;
	protected string $status = self::STATUS_ACTIVE;
	protected string $reviewState = self::REVIEW_DRAFT;
	protected ?string $sourceMessageId = null;
	protected ?float $confidence = null;
	/** Canonical per-type structured payload, stored as a JSON string. */
	protected ?string $details = null;
	protected ?\DateTime $startDate = null;
	protected ?\DateTime $endDate = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;
	protected ?\DateTime $confirmedAt = null;

	public function __construct() {
		$this->addType('tripId', 'integer');
		$this->addType('confidence', 'float');
		$this->addType('startDate', 'datetime');
		$this->addType('endDate', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('confirmedAt', 'datetime');
	}

	/**
	 * Decode the stored JSON details into an object for the API, or an empty
	 * object when absent/corrupt (the type-specific shape is validated on write
	 * in ExtractionService).
	 *
	 * @return array<string, mixed>
	 */
	public function decodedDetails(): array {
		if ($this->details === null || $this->details === '') {
			return [];
		}
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($this->details, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @return TravelManagerBooking
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'tripId' => $this->tripId,
			'type' => $this->type,
			'provider' => $this->provider,
			'bookingReference' => $this->bookingReference,
			'confirmationNumber' => $this->confirmationNumber,
			'title' => $this->title,
			'status' => $this->status,
			'reviewState' => $this->reviewState,
			'confidence' => $this->confidence,
			// The RFC Message-ID of the email that created this booking. Nothing
			// updates an existing booking (one message = one booking), so this
			// genuinely means "created by" — it is the trail back to the source.
			'sourceMessageId' => $this->sourceMessageId,
			'details' => $this->decodedDetails(),
			// Local wall-clock span: emit without timezone offset (see V8).
			'startDate' => $this->startDate?->format('Y-m-d\TH:i:s'),
			'endDate' => $this->endDate?->format('Y-m-d\TH:i:s'),
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
			'confirmedAt' => $this->confirmedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
