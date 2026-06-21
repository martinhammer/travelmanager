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
 * @psalm-import-type TravelManagerTrip from \OCA\TravelManager\ResponseDefinitions
 *
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

	/**
	 * List the current user's trips
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TravelManagerTrip>, array{}>
	 *
	 * 200: Trips returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/trips')]
	public function index(): DataResponse {
		$trips = array_values(array_map(static fn ($t): array => $t->jsonSerialize(), $this->bookingService->listTrips($this->uid())));
		return new DataResponse($trips);
	}

	/**
	 * Create a trip
	 *
	 * @param string $name Name of the trip
	 * @param string|null $notes Optional notes
	 * @return DataResponse<Http::STATUS_OK, TravelManagerTrip, array{}>
	 *
	 * 200: Trip created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/trips')]
	public function create(string $name, ?string $notes = null): DataResponse {
		$trip = $this->bookingService->createTrip($this->uid(), $name, $notes);
		return new DataResponse($trip->jsonSerialize());
	}

	/**
	 * Update a trip
	 *
	 * @param int $id Id of the trip
	 * @param string|null $name New name
	 * @param string|null $notes New notes
	 * @return DataResponse<Http::STATUS_OK, TravelManagerTrip, array{}>
	 * @throws OCSNotFoundException Trip not found
	 *
	 * 200: Trip updated
	 * 404: Trip not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/trips/{id}')]
	public function update(int $id, ?string $name = null, ?string $notes = null): DataResponse {
		$values = array_filter([
			'name' => $name,
			'notes' => $notes,
		], static fn ($v): bool => $v !== null);
		try {
			$trip = $this->bookingService->updateTrip($this->uid(), $id, $values);
			return new DataResponse($trip->jsonSerialize());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Delete a trip (member bookings are unlinked, not deleted)
	 *
	 * @param int $id Id of the trip
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 * @throws OCSNotFoundException Trip not found
	 *
	 * 200: Trip deleted
	 * 404: Trip not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/trips/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$this->bookingService->deleteTrip($this->uid(), $id);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
