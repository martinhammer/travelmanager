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
	public const STATUS_DRAFT = 'draft';
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_SUPERSEDED = 'superseded';

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
	protected string $status = self::STATUS_DRAFT;
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
			'confidence' => $this->confidence,
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
