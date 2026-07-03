<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Db\IngestionLogMapper;
use OCA\TravelManager\Service\IngestionLogger;
use OCA\TravelManager\Service\IngestionService;
use OCA\TravelManager\Service\MaintenanceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Developer/debugging endpoints for the current user: trigger a mailbox read on
 * demand (no waiting for cron), inspect the step-by-step activity log, and wipe
 * the derived data so the same messages can be reprocessed from scratch.
 *
 * @psalm-import-type TravelManagerLog from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class DevController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private IngestionService $ingestionService,
		private IngestionLogMapper $logMapper,
		private IngestionLogger $activityLog,
		private MaintenanceService $maintenanceService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Read the mailbox now and schedule extraction for any new messages
	 *
	 * @return DataResponse<Http::STATUS_OK, array{enqueued: int}, array{}>
	 * @throws OCSBadRequestException Mailbox could not be read
	 *
	 * 200: Number of newly scheduled messages returned
	 * 400: Mailbox could not be read
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/dev/ingest')]
	public function ingest(): DataResponse {
		try {
			$enqueued = $this->ingestionService->ingestForUser($this->uid());
		} catch (\Throwable $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
		return new DataResponse(['enqueued' => $enqueued]);
	}

	/**
	 * List the current user's ingestion activity log, newest first
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TravelManagerLog>, array{}>
	 *
	 * 200: Activity log returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/dev/logs')]
	public function logs(): DataResponse {
		$entries = $this->logMapper->findForUser($this->uid());
		return new DataResponse(array_values(array_map(static fn ($l): array => $l->jsonSerialize(), $entries)));
	}

	/**
	 * Clear the current user's ingestion activity log
	 *
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Activity log cleared
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/dev/logs')]
	public function clearLogs(): DataResponse {
		$this->activityLog->clear($this->uid());
		return new DataResponse(['success' => true]);
	}

	/**
	 * Wipe the current user's extracted travel data so the mailbox is reprocessed
	 *
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Data wiped
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/dev/data')]
	public function wipe(): DataResponse {
		$this->maintenanceService->wipeUserData($this->uid());
		return new DataResponse(['success' => true]);
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
