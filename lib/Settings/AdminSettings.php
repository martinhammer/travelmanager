<?php

declare(strict_types=1);

namespace OCA\TravelManager\Settings;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConfigService $configService,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('adminSettings', [
			'enabled' => $this->configService->isFeatureEnabled(),
			'rateLimitPerRun' => $this->configService->getRateLimitPerRun(),
			'localConcurrency' => $this->configService->getLocalConcurrency(),
		]);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-adminSettings');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-adminSettings');
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
