<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Admin-only settings: the global feature flag, how much of each mailbox a run
 * reads, and the two limits on how hard extraction leans on the model.
 *
 * @psalm-import-type TravelManagerAdminSettings from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class AdminController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the global Travel Manager admin settings
	 *
	 * @return DataResponse<Http::STATUS_OK, TravelManagerAdminSettings, array{}>
	 *
	 * 200: Admin settings returned
	 */
	#[ApiRoute(verb: 'GET', url: '/api/admin/settings')]
	public function show(): DataResponse {
		return new DataResponse([
			'enabled' => $this->configService->isFeatureEnabled(),
			'fetchPerRun' => $this->configService->getFetchPerRun(),
			'maxInFlight' => $this->configService->getMaxInFlight(),
			'maxPerHour' => $this->configService->getMaxPerHour(),
		]);
	}

	/**
	 * Update the global Travel Manager admin settings
	 *
	 * @param bool|null $enabled Whether the extraction pipeline is enabled instance-wide
	 * @param int|null $fetchPerRun How many of the newest messages one mailbox read looks at, per user
	 * @param int|null $maxInFlight Most extractions waiting on the model at once, instance-wide (at least 1)
	 * @param int|null $maxPerHour Most extractions started per rolling hour, instance-wide (0 for no limit)
	 * @return DataResponse<Http::STATUS_OK, TravelManagerAdminSettings, array{}>
	 *
	 * 200: Updated admin settings returned
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/admin/settings')]
	public function update(?bool $enabled = null, ?int $fetchPerRun = null, ?int $maxInFlight = null, ?int $maxPerHour = null): DataResponse {
		if ($enabled !== null) {
			$this->configService->setFeatureEnabled($enabled);
		}
		if ($fetchPerRun !== null) {
			$this->configService->setFetchPerRun($fetchPerRun);
		}
		if ($maxInFlight !== null) {
			$this->configService->setMaxInFlight($maxInFlight);
		}
		if ($maxPerHour !== null) {
			$this->configService->setMaxPerHour($maxPerHour);
		}
		return $this->show();
	}
}
