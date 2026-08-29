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
	 * @param string|null $type Trip type: work or leisure
	 * @param string|null $color Trip colour as #rrggbb
	 * @return DataResponse<Http::STATUS_OK, TravelManagerTrip, array{}>
	 * @throws OCSBadRequestException Unknown trip type or malformed colour
	 *
	 * 200: Trip created
	 * 400: Unknown trip type or malformed colour
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/trips')]
	public function create(string $name, ?string $notes = null, ?string $type = null, ?string $color = null): DataResponse {
		try {
			$trip = $this->bookingService->createTrip($this->uid(), $name, $notes, $type, $color);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
		return new DataResponse($trip->jsonSerialize());
	}

	/**
	 * Update a trip
	 *
	 * @param int $id Id of the trip
	 * @param string|null $name New name
	 * @param string|null $notes New notes
	 * @param string|null $type New type: work, leisure, or '' to clear
	 * @param string|null $color New colour as #rrggbb, or '' to clear
	 * @return DataResponse<Http::STATUS_OK, TravelManagerTrip, array{}>
	 * @throws OCSNotFoundException Trip not found
	 * @throws OCSBadRequestException Unknown trip type or malformed colour
	 *
	 * 200: Trip updated
	 * 400: Unknown trip type or malformed colour
	 * 404: Trip not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/trips/{id}')]
	public function update(int $id, ?string $name = null, ?string $notes = null, ?string $type = null, ?string $color = null): DataResponse {
		// Only supplied fields are touched, so an omitted one keeps its value.
		// Type and colour are clearable, and the empty string is how that is said
		// — null here means "not supplied", which is a different request.
		$values = array_filter([
			'name' => $name,
			'notes' => $notes,
			'type' => $type,
			'color' => $color,
		], static fn ($v): bool => $v !== null);
		try {
			$trip = $this->bookingService->updateTrip($this->uid(), $id, $values);
			return new DataResponse($trip->jsonSerialize());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
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
