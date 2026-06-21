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
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Trip extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected string $name = '';
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

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'startDate' => $this->startDate?->format('Y-m-d'),
			'endDate' => $this->endDate?->format('Y-m-d'),
			'notes' => $this->notes,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
