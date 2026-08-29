<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method \DateTime|null getStartDate()
 * @method void setStartDate(?\DateTime $startDate)
 * @method \DateTime|null getEndDate()
 * @method void setEndDate(?\DateTime $endDate)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 *
 * @psalm-import-type TravelManagerTrip from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Trip extends Entity implements \JsonSerializable {
	public const TYPE_WORK = 'work';
	public const TYPE_LEISURE = 'leisure';

	/**
	 * The types a trip may carry. Deliberately open to growth — work/leisure is a
	 * starting point, and the column stores whatever slug is here rather than an
	 * enum, so adding one is a change to this list alone.
	 */
	public const TYPES = [self::TYPE_WORK, self::TYPE_LEISURE];

	protected string $userId = '';
	protected string $name = '';
	protected ?string $type = null;
	protected ?string $color = null;
	protected ?\DateTime $startDate = null;
	protected ?\DateTime $endDate = null;
	protected ?string $notes = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('startDate', 'datetime');
		$this->addType('endDate', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * @return TravelManagerTrip
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'type' => $this->type,
			'color' => $this->color,
			'startDate' => $this->startDate?->format('Y-m-d'),
			'endDate' => $this->endDate?->format('Y-m-d'),
			'notes' => $this->notes,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
