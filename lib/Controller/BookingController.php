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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/bookings')]
	public function index(?string $status = null): DataResponse {
		$bookings = $this->bookingService->listBookings($this->uid(), $status);
		$out = array_map(fn ($b) => $this->serialize($b->getId()), $bookings);
		return new DataResponse($out);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/bookings/{id}')]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/bookings/{id}')]
	public function update(int $id, ?string $title = null, ?string $provider = null, ?string $bookingReference = null): DataResponse {
		$values = array_filter([
			'title' => $title,
			'provider' => $provider,
			'bookingReference' => $bookingReference,
		], static fn ($v) => $v !== null);
		try {
			$this->bookingService->updateBookingFields($this->uid(), $id, $values);
			return new DataResponse($this->serialize($id));
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/bookings/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$this->bookingService->discard($this->uid(), $id);
			return new DataResponse([], Http::STATUS_OK);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

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

	private function serialize(int $bookingId): array {
		$uid = $this->uid();
		$booking = $this->bookingService->getBooking($uid, $bookingId);
		$segments = $this->bookingService->listSegments($uid, $bookingId);
		return [
			'booking' => $booking->jsonSerialize(),
			'segments' => array_map(static fn ($s) => $s->jsonSerialize(), $segments),
		];
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
