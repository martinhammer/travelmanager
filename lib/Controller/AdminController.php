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
 * Admin-only settings: global feature flag and LLM throttling.
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
			'rateLimitPerRun' => $this->configService->getRateLimitPerRun(),
			'localConcurrency' => $this->configService->getLocalConcurrency(),
		]);
	}

	/**
	 * Update the global Travel Manager admin settings
	 *
	 * @param bool|null $enabled Whether the extraction pipeline is enabled instance-wide
	 * @param int|null $rateLimitPerRun Max messages processed per user per run
	 * @param int|null $localConcurrency Max concurrent local-model extractions
	 * @return DataResponse<Http::STATUS_OK, TravelManagerAdminSettings, array{}>
	 *
	 * 200: Updated admin settings returned
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/admin/settings')]
	public function update(?bool $enabled = null, ?int $rateLimitPerRun = null, ?int $localConcurrency = null): DataResponse {
		if ($enabled !== null) {
			$this->configService->setFeatureEnabled($enabled);
		}
		if ($rateLimitPerRun !== null) {
			$this->configService->setRateLimitPerRun($rateLimitPerRun);
		}
		if ($localConcurrency !== null) {
			$this->configService->setLocalConcurrency($localConcurrency);
		}
		return $this->show();
	}
}
