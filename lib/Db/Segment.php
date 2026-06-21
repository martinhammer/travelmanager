<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getBookingId()
 * @method void setBookingId(int $bookingId)
 * @method int getSequence()
 * @method void setSequence(int $sequence)
 * @method \DateTime|null getStartLocal()
 * @method void setStartLocal(?\DateTime $startLocal)
 * @method string|null getStartTimezone()
 * @method void setStartTimezone(?string $startTimezone)
 * @method \DateTime|null getEndLocal()
 * @method void setEndLocal(?\DateTime $endLocal)
 * @method string|null getEndTimezone()
 * @method void setEndTimezone(?string $endTimezone)
 * @method string|null getOrigin()
 * @method void setOrigin(?string $origin)
 * @method string|null getDestination()
 * @method void setDestination(?string $destination)
 * @method string|null getLocation()
 * @method void setLocation(?string $location)
 * @method string|null getFlightNumber()
 * @method void setFlightNumber(?string $flightNumber)
 * @method string|null getCarrier()
 * @method void setCarrier(?string $carrier)
 * @method string|null getSeat()
 * @method void setSeat(?string $seat)
 * @method string|null getTerminal()
 * @method void setTerminal(?string $terminal)
 * @method string|null getGate()
 * @method void setGate(?string $gate)
 * @method string|null getExtraJson()
 * @method void setExtraJson(?string $extraJson)
 * @method float|null getConfidence()
 * @method void setConfidence(?float $confidence)
 *
 * @psalm-import-type TravelManagerSegment from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Segment extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected int $bookingId = 0;
	protected int $sequence = 0;
	protected ?\DateTime $startLocal = null;
	protected ?string $startTimezone = null;
	protected ?\DateTime $endLocal = null;
	protected ?string $endTimezone = null;
	protected ?string $origin = null;
	protected ?string $destination = null;
	protected ?string $location = null;
	protected ?string $flightNumber = null;
	protected ?string $carrier = null;
	protected ?string $seat = null;
	protected ?string $terminal = null;
	protected ?string $gate = null;
	protected ?string $extraJson = null;
	protected ?float $confidence = null;

	public function __construct() {
		$this->addType('bookingId', 'integer');
		$this->addType('sequence', 'integer');
		$this->addType('startLocal', 'datetime');
		$this->addType('endLocal', 'datetime');
		$this->addType('confidence', 'float');
	}

	/**
	 * @return TravelManagerSegment
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bookingId' => $this->bookingId,
			'sequence' => $this->sequence,
			// Local wall-clock times: emit without timezone offset (see V8).
			'startLocal' => $this->startLocal?->format('Y-m-d\TH:i:s'),
			'startTimezone' => $this->startTimezone,
			'endLocal' => $this->endLocal?->format('Y-m-d\TH:i:s'),
			'endTimezone' => $this->endTimezone,
			'origin' => $this->origin,
			'destination' => $this->destination,
			'location' => $this->location,
			'flightNumber' => $this->flightNumber,
			'carrier' => $this->carrier,
			'seat' => $this->seat,
			'terminal' => $this->terminal,
			'gate' => $this->gate,
			'confidence' => $this->confidence,
		];
	}
}
