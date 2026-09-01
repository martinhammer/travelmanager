<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\Booking;
use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\Trip;
use OCA\TravelManager\Db\TripMapper;
use OCA\TravelManager\Service\Dto\AppliedExtraction;
use OCA\TravelManager\Service\Dto\BookingMatch;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCA\TravelManager\Service\Dto\MatchCandidate;
use OCA\TravelManager\Service\Dto\RelatedBooking;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Canonical store operations for bookings and trips. Every method is scoped by
 * user id. Persists LLM extractions as drafts, deduplicating a second email
 * about a booking the user already has — see BookingMatcher for the rules and
 * why the old (type, provider, reference) key was not enough. Type-specific
 * structure is stored verbatim as JSON in the booking's `details` column.
 */
class BookingService {
	/**
	 * How far either side of an incoming booking's start date to look for the
	 * booking it might duplicate. Wide enough that a reminder email quoting a
	 * bare date, or a model that read the wrong one of two dates in a
	 * confirmation, still lands on the original; narrow enough that a whole
	 * season of travel is not a candidate. Only ever widens the *candidate* set
	 * — BookingMatcher still has to find agreeing evidence.
	 */
	private const MATCH_WINDOW_DAYS = 14;

	public function __construct(
		private BookingMapper $bookingMapper,
		private TripMapper $tripMapper,
		private BookingMatcher $matcher,
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Persist a set of extracted bookings for one source message.
	 *
	 * One message creates a booking at most once. A booking the matcher
	 * recognises as one the user already has is **not** applied — see
	 * AppliedExtraction for why — and is reported back so the user can be told.
	 * A booking that merely *resembles* one is stored and reported: suppressing
	 * it would be the only irreversible mistake available here.
	 *
	 * @param ExtractedBooking[] $bookings
	 */
	public function applyExtraction(string $userId, string $messageId, array $bookings): AppliedExtraction {
		$created = 0;
		/** @var list<RelatedBooking> $related */
		$related = [];
		$this->db->beginTransaction();
		try {
			foreach ($bookings as $extracted) {
				$match = $this->matcher->match($extracted, $this->findCandidates($userId, $extracted));
				if ($match !== null && $match->decisive) {
					$related[] = $this->report($match, $extracted);
					continue;
				}
				$this->insertBooking($userId, $messageId, $extracted);
				$created++;
				if ($match !== null) {
					$related[] = $this->report($match, $extracted);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return new AppliedExtraction($created, $related);
	}

	/**
	 * Existing bookings this extraction might be a second email about.
	 *
	 * The identifiers go in raw as well as normalised: the column holds whatever
	 * the model wrote, so a literal hit needs the raw form, while the normalised
	 * form catches the same reference typed with different punctuation.
	 *
	 * @return list<MatchCandidate>
	 */
	private function findCandidates(string $userId, ExtractedBooking $extracted): array {
		$identifiers = [];
		foreach ([$extracted->bookingReference, $extracted->confirmationNumber] as $value) {
			if ($value !== null && $value !== '') {
				$identifiers[] = $value;
			}
			$normalized = $this->matcher->normalizeIdentifier($value);
			if ($normalized !== null) {
				$identifiers[] = $normalized;
			}
		}

		$rows = $this->bookingMapper->findMatchCandidates(
			$userId,
			$extracted->type,
			$this->toDateTime($extracted->startDate),
			self::MATCH_WINDOW_DAYS,
			array_values(array_unique($identifiers)),
		);

		return array_values(array_map(
			static fn (Booking $booking): MatchCandidate => new MatchCandidate(
				$booking->getId(),
				$booking->getType(),
				$booking->getProvider(),
				$booking->getBookingReference(),
				$booking->getConfirmationNumber(),
				$booking->decodedDetails(),
				$booking->getStartDate()?->format('Y-m-d\TH:i:s'),
				$booking->getReviewState(),
			),
			$rows,
		));
	}

	private function insertBooking(string $userId, string $messageId, ExtractedBooking $extracted): void {
		$now = $this->timeFactory->getDateTime();
		$booking = new Booking();
		$booking->setUserId($userId);
		$booking->setType($extracted->type);
		$booking->setStatus($extracted->status === 'cancelled' ? Booking::STATUS_CANCELLED : Booking::STATUS_ACTIVE);
		$booking->setReviewState(Booking::REVIEW_DRAFT);
		$booking->setCreatedAt($now);
		$booking->setUpdatedAt($now);
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
		$this->bookingMapper->insert($booking);
	}

	private function report(BookingMatch $match, ExtractedBooking $extracted): RelatedBooking {
		return new RelatedBooking(
			$match->candidate->id,
			$this->describeRelated($match, $extracted),
			$match->reason,
			$match->decisive,
		);
	}

	/**
	 * Explain what this email matched, on what evidence, and what was (not) done
	 * about it. Two things are called out by name because leaving the booking
	 * untouched matters most there: a cancellation, and a match we were not sure
	 * enough of to act on.
	 */
	private function describeRelated(BookingMatch $match, ExtractedBooking $extracted): string {
		$existing = $match->candidate;
		$label = $extracted->title ?? $extracted->bookingReference ?? $extracted->type;
		$named = 'the existing booking #' . (string)$existing->id
			. ' (' . $existing->type . ', ' . ($existing->bookingReference ?? 'no reference')
			. ', ' . $existing->reviewState . ')';

		if (!$match->decisive) {
			return '"' . $label . '" may duplicate ' . $named . ' — ' . $match->evidence
				. '. It was saved as a new draft rather than dropped; discard whichever is wrong.';
		}

		$line = '"' . $label . '" matches ' . $named . ' on ' . $match->evidence;
		if ($extracted->status === 'cancelled') {
			return $line . ', and reports it as CANCELLED. Updates are not supported yet, so the booking'
				. ' was left unchanged — cancel or discard it yourself.';
		}
		return $line . '. Updates are not supported yet, so the booking was left unchanged.';
	}

	/* --------------------------------------------------------------- reads */

	/**
	 * @return Booking[]
	 */
	public function listBookings(string $userId, ?string $reviewState = null): array {
		if ($reviewState !== null) {
			return $this->bookingMapper->findByReviewState($userId, $reviewState);
		}
		return $this->bookingMapper->findAllForUser($userId);
	}

	public function getBooking(string $userId, int $bookingId): Booking {
		return $this->bookingMapper->find($bookingId, $userId);
	}

	/* --------------------------------------------------------- review state */

	/**
	 * Move a booking to a review state (see Booking::REVIEW_STATES).
	 *
	 * Discarding and archiving are soft: the row is kept, so the user can undo
	 * and so a later email about the same booking cannot resurrect it as a fresh
	 * draft. Use purge() to delete a booking for good.
	 *
	 * @throws \InvalidArgumentException on an unknown review state
	 */
	public function setReviewState(string $userId, int $bookingId, string $reviewState): Booking {
		if (!in_array($reviewState, Booking::REVIEW_STATES, true)) {
			throw new \InvalidArgumentException('Unknown review state: ' . $reviewState);
		}
		$booking = $this->bookingMapper->find($bookingId, $userId);
		$booking->setReviewState($reviewState);
		if ($reviewState === Booking::REVIEW_CONFIRMED && $booking->getConfirmedAt() === null) {
			$booking->setConfirmedAt($this->timeFactory->getDateTime());
		}
		$booking->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->bookingMapper->update($booking);
	}

	/**
	 * Delete a booking for good. Unlike discarding, this leaves no tombstone, so
	 * a later email about the same booking will re-create it as a draft.
	 */
	public function purge(string $userId, int $bookingId): void {
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

	/**
	 * @throws \InvalidArgumentException when the type or colour is not one we store
	 */
	public function createTrip(string $userId, string $name, ?string $notes = null, ?string $type = null, ?string $color = null): Trip {
		$now = $this->timeFactory->getDateTime();
		$trip = new Trip();
		$trip->setUserId($userId);
		$trip->setName($name);
		$trip->setNotes($notes);
		$trip->setType($this->validTripType($type));
		$trip->setColor($this->validColor($color));
		$trip->setCreatedAt($now);
		$trip->setUpdatedAt($now);
		return $this->tripMapper->insert($trip);
	}

	/**
	 * Update a trip's user-entered fields.
	 *
	 * Keys absent from $values are left alone; a key present with null **clears**
	 * the field, which is how the colour picker's "clear" and an unset type get
	 * through. That is why this takes an array rather than nullable parameters —
	 * with those, "not supplied" and "clear it" are the same value.
	 *
	 * @param array{name?:string,notes?:string,type?:string|null,color?:string|null} $values
	 * @throws \InvalidArgumentException when the type or colour is not one we store
	 */
	public function updateTrip(string $userId, int $tripId, array $values): Trip {
		$trip = $this->tripMapper->find($tripId, $userId);
		if (array_key_exists('name', $values)) {
			$trip->setName($values['name']);
		}
		if (array_key_exists('notes', $values)) {
			$trip->setNotes($values['notes']);
		}
		if (array_key_exists('type', $values)) {
			$trip->setType($this->validTripType($values['type']));
		}
		if (array_key_exists('color', $values)) {
			$trip->setColor($this->validColor($values['color']));
		}
		$trip->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->tripMapper->update($trip);
	}

	/**
	 * An empty string means "no type", so clearing one does not have to be a
	 * separate request shape from setting one.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validTripType(?string $type): ?string {
		if ($type === null || $type === '') {
			return null;
		}
		if (!in_array($type, Trip::TYPES, true)) {
			throw new \InvalidArgumentException('Unknown trip type: ' . $type);
		}
		return $type;
	}

	/**
	 * Colours are stored exactly as CSS and NcColorPicker use them, so the only
	 * thing to check is that it *is* that form — this value ends up interpolated
	 * into a style attribute.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validColor(?string $color): ?string {
		if ($color === null || $color === '') {
			return null;
		}
		if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new \InvalidArgumentException('Colour must be #rrggbb: ' . $color);
		}
		return strtolower($color);
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
