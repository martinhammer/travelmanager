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
class TripController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private BookingService $bookingService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/trips')]
	public function index(): DataResponse {
		$trips = array_map(static fn ($t) => $t->jsonSerialize(), $this->bookingService->listTrips($this->uid()));
		return new DataResponse($trips);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/trips')]
	public function create(string $name, ?string $notes = null): DataResponse {
		$trip = $this->bookingService->createTrip($this->uid(), $name, $notes);
		return new DataResponse($trip->jsonSerialize());
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/trips/{id}')]
	public function update(int $id, ?string $name = null, ?string $notes = null): DataResponse {
		$values = array_filter([
			'name' => $name,
			'notes' => $notes,
		], static fn ($v) => $v !== null);
		try {
			$trip = $this->bookingService->updateTrip($this->uid(), $id, $values);
			return new DataResponse($trip->jsonSerialize());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/trips/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$this->bookingService->deleteTrip($this->uid(), $id);
			return new DataResponse([], Http::STATUS_OK);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
