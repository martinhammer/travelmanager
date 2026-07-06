<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\Booking;
use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\Trip;
use OCA\TravelManager\Db\TripMapper;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Canonical store operations for bookings and trips. Every method is scoped by
 * user id. Persists LLM extractions as drafts, applying update / cancellation
 * idempotency keyed on (type, provider, reference) — see V6. Type-specific
 * structure is stored verbatim as JSON in the booking's `details` column.
 */
class BookingService {
	public function __construct(
		private BookingMapper $bookingMapper,
		private TripMapper $tripMapper,
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Persist a set of extracted bookings for one source message. Existing
	 * bookings matched by natural key are updated in place (never duplicated);
	 * a 'cancelled' extraction cancels the matched booking.
	 *
	 * @param ExtractedBooking[] $bookings
	 * @return int number of bookings created or updated
	 */
	public function applyExtraction(string $userId, string $messageId, array $bookings): int {
		$count = 0;
		$this->db->beginTransaction();
		try {
			foreach ($bookings as $extracted) {
				$this->applyOne($userId, $messageId, $extracted);
				$count++;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $count;
	}

	private function applyOne(string $userId, string $messageId, ExtractedBooking $extracted): void {
		$now = $this->timeFactory->getDateTime();
		$existing = $this->bookingMapper->findByReference(
			$userId,
			$extracted->type,
			$extracted->provider,
			$extracted->bookingReference,
		);

		if ($existing !== null) {
			$booking = $existing;
			$booking->setUpdatedAt($now);
		} else {
			$booking = new Booking();
			$booking->setUserId($userId);
			$booking->setType($extracted->type);
			$booking->setStatus(Booking::STATUS_DRAFT);
			$booking->setCreatedAt($now);
			$booking->setUpdatedAt($now);
		}

		$booking->setProvider($extracted->provider);
		$booking->setBookingReference($extracted->bookingReference);
		$booking->setConfirmationNumber($extracted->confirmationNumber);
		$booking->setTitle($extracted->title);
		$booking->setSourceMessageId($messageId);
		$booking->setConfidence($extracted->confidence);
		$encodedDetails = json_encode($extracted->details);
		$booking->setDetails($encodedDetails === false ? null : $encodedDetails);
		$booking->setStartDate($this->toDateTime($extracted->startDate));
		$booking->setEndDate($this->toDateTime($extracted->endDate));

		if ($extracted->status === 'cancelled') {
			$booking->setStatus(Booking::STATUS_CANCELLED);
		} elseif ($existing === null) {
			$booking->setStatus(Booking::STATUS_DRAFT);
		}

		if ($existing !== null) {
			$this->bookingMapper->update($booking);
		} else {
			$this->bookingMapper->insert($booking);
		}
	}

	/* --------------------------------------------------------------- reads */

	/**
	 * @return Booking[]
	 */
	public function listBookings(string $userId, ?string $status = null): array {
		if ($status !== null) {
			return $this->bookingMapper->findByStatus($userId, $status);
		}
		return $this->bookingMapper->findAllForUser($userId);
	}

	public function getBooking(string $userId, int $bookingId): Booking {
		return $this->bookingMapper->find($bookingId, $userId);
	}

	/* -------------------------------------------------------- draft/confirm */

	public function confirm(string $userId, int $bookingId): Booking {
		$booking = $this->bookingMapper->find($bookingId, $userId);
		$booking->setStatus(Booking::STATUS_CONFIRMED);
		$booking->setConfirmedAt($this->timeFactory->getDateTime());
		return $this->bookingMapper->update($booking);
	}

	public function discard(string $userId, int $bookingId): void {
		$booking = $this->bookingMapper->find($bookingId, $userId);
		$this->bookingMapper->delete($booking);
	}

	/**
	 * Apply user edits to a draft booking's own fields.
	 *
	 * @param array{title?:string,provider?:string,bookingReference?:string,confirmationNumber?:string} $values
	 */
	public function updateBookingFields(string $userId, int $bookingId, array $values): Booking {
		$booking = $this->bookingMapper->find($bookingId, $userId);
		if (array_key_exists('title', $values)) {
			$booking->setTitle($values['title']);
		}
		if (array_key_exists('provider', $values)) {
			$booking->setProvider($values['provider']);
		}
		if (array_key_exists('bookingReference', $values)) {
			$booking->setBookingReference($values['bookingReference']);
		}
		if (array_key_exists('confirmationNumber', $values)) {
			$booking->setConfirmationNumber($values['confirmationNumber']);
		}
		$booking->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->bookingMapper->update($booking);
	}

	/* ------------------------------------------------------------- trips */

	/**
	 * @return Trip[]
	 */
	public function listTrips(string $userId): array {
		return $this->tripMapper->findAllForUser($userId);
	}

	public function createTrip(string $userId, string $name, ?string $notes = null): Trip {
		$now = $this->timeFactory->getDateTime();
		$trip = new Trip();
		$trip->setUserId($userId);
		$trip->setName($name);
		$trip->setNotes($notes);
		$trip->setCreatedAt($now);
		$trip->setUpdatedAt($now);
		return $this->tripMapper->insert($trip);
	}

	/**
	 * @param array{name?:string,notes?:string} $values
	 */
	public function updateTrip(string $userId, int $tripId, array $values): Trip {
		$trip = $this->tripMapper->find($tripId, $userId);
		if (array_key_exists('name', $values)) {
			$trip->setName($values['name']);
		}
		if (array_key_exists('notes', $values)) {
			$trip->setNotes($values['notes']);
		}
		$trip->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->tripMapper->update($trip);
	}

	public function deleteTrip(string $userId, int $tripId): void {
		$trip = $this->tripMapper->find($tripId, $userId);
		// Unlink member bookings rather than deleting them.
		foreach ($this->bookingMapper->findByTrip($userId, $tripId) as $booking) {
			$booking->setTripId(null);
			$this->bookingMapper->update($booking);
		}
		$this->tripMapper->delete($trip);
	}

	/**
	 * Link (or, with $tripId === null, unlink) a booking to a trip.
	 *
	 * @throws DoesNotExistException when booking or trip is not owned by the user
	 */
	public function assignBookingToTrip(string $userId, int $bookingId, ?int $tripId): Booking {
		$booking = $this->bookingMapper->find($bookingId, $userId);
		if ($tripId !== null) {
			// Verifies ownership; throws if the trip is not the user's.
			$this->tripMapper->find($tripId, $userId);
		}
		$booking->setTripId($tripId);
		$booking->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->bookingMapper->update($booking);
	}

	/* ------------------------------------------------------------- helpers */

	private function toDateTime(?string $value): ?\DateTime {
		if ($value === null) {
			return null;
		}
		$dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $value);
		return $dt === false ? null : $dt;
	}
}
