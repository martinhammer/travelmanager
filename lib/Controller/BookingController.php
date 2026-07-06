<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Service\BookingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
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
	 * @param string|null $status Only return bookings with this status (draft, confirmed, cancelled, superseded)
	 * @return DataResponse<Http::STATUS_OK, list<TravelManagerBooking>, array{}>
	 *
	 * 200: Bookings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/bookings')]
	public function index(?string $status = null): DataResponse {
		$bookings = $this->bookingService->listBookings($this->uid(), $status);
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
	 * Confirm a draft booking
	 *
	 * @param int $id Id of the booking
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBooking, array{}>
	 * @throws OCSNotFoundException Booking not found
	 *
	 * 200: Booking confirmed
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/bookings/{id}/confirm')]
	public function confirm(int $id): DataResponse {
		try {
			$this->bookingService->confirm($this->uid(), $id);
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Discard (delete) a booking
	 *
	 * @param int $id Id of the booking
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 * @throws OCSNotFoundException Booking not found
	 *
	 * 200: Booking discarded
	 * 404: Booking not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/bookings/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$this->bookingService->discard($this->uid(), $id);
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
