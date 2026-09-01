<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Service\BookingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TravelManagerBooking from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class BookingController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private BookingService $bookingService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the current user's bookings, each with its type-specific details
	 *
	 * @param string|null $reviewState Only return bookings in this review state (draft, confirmed, discarded, archived)
	 * @return DataResponse<Http::STATUS_OK, list<TravelManagerBooking>, array{}>
	 *
	 * 200: Bookings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/bookings')]
	public function index(?string $reviewState = null): DataResponse {
		$bookings = $this->bookingService->listBookings($this->uid(), $reviewState);
		$out = array_values(array_map(static fn ($b): array => $b->jsonSerialize(), $bookings));
		return new DataResponse($out);
	}

	/**
	 * Get a single booking with its type-specific details
	 *
	 * @param int $id Id of the booking
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBooking, array{}>
	 * @throws OCSNotFoundException Booking not found
	 *
	 * 200: Booking returned
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/bookings/{id}')]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Update a draft booking's editable fields
	 *
	 * @param int $id Id of the booking
	 * @param string|null $title New title
	 * @param string|null $provider New provider
	 * @param string|null $bookingReference New booking reference
	 * @param string|null $confirmationNumber New confirmation number
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBooking, array{}>
	 * @throws OCSNotFoundException Booking not found
	 *
	 * 200: Booking updated
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/bookings/{id}')]
	public function update(int $id, ?string $title = null, ?string $provider = null, ?string $bookingReference = null, ?string $confirmationNumber = null): DataResponse {
		$values = array_filter([
			'title' => $title,
			'provider' => $provider,
			'bookingReference' => $bookingReference,
			'confirmationNumber' => $confirmationNumber,
		], static fn ($v): bool => $v !== null);
		try {
			$this->bookingService->updateBookingFields($this->uid(), $id, $values);
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Move a booking to a review state
	 *
	 * Discarding and archiving are soft: the booking is kept and can be moved
	 * back, and a later email about it will not resurrect it as a fresh draft.
	 *
	 * @param int $id Id of the booking
	 * @param string $reviewState Target state: draft, confirmed, discarded or archived
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBooking, array{}>
	 * @throws OCSNotFoundException Booking not found
	 * @throws OCSBadRequestException Unknown review state
	 *
	 * 200: Booking updated
	 * 400: Unknown review state
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/bookings/{id}/review')]
	public function review(int $id, string $reviewState): DataResponse {
		try {
			$this->bookingService->setReviewState($this->uid(), $id, $reviewState);
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	/**
	 * Dismiss a possible-duplicate flag on a booking
	 *
	 * Clears the flag from both bookings in the pair: it reads the same on both
	 * cards, so dismissing it on one and not the other would leave a lie on the
	 * other. Not a review transition — the two state axes stay orthogonal, and
	 * this is neither a fact about the booking nor a decision about keeping it.
	 *
	 * @param int $id Id of the booking
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBooking, array{}>
	 * @throws OCSNotFoundException Booking not found
	 *
	 * 200: Flag cleared
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/bookings/{id}/duplicate')]
	public function dismissDuplicate(int $id): DataResponse {
		try {
			$this->bookingService->clearPossibleDuplicate($this->uid(), $id);
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Delete a booking permanently
	 *
	 * Unlike discarding this leaves no tombstone, so a later email about the
	 * same booking will re-create it as a draft.
	 *
	 * @param int $id Id of the booking
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 * @throws OCSNotFoundException Booking not found
	 *
	 * 200: Booking deleted
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/bookings/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$this->bookingService->purge($this->uid(), $id);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Link a booking to a trip, or unlink it when tripId is null
	 *
	 * @param int $id Id of the booking
	 * @param int|null $tripId Id of the trip to link, or null to unlink
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBooking, array{}>
	 * @throws OCSNotFoundException Booking or trip not found
	 *
	 * 200: Booking linked
	 * 404: Booking or trip not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/bookings/{id}/trip')]
	public function assignTrip(int $id, ?int $tripId = null): DataResponse {
		try {
			$this->bookingService->assignBookingToTrip($this->uid(), $id, $tripId);
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * @return TravelManagerBooking
	 * @throws DoesNotExistException
	 */
	private function serialize(int $bookingId): array {
		return $this->bookingService->getBooking($this->uid(), $bookingId)->jsonSerialize();
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
