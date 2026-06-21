<?php

declare(strict_types=1);

namespace OCA\TravelManager\Settings;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConfigService $configService,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->initialState->provideInitialState('settings', $this->configService->getUserSettings($user->getUID()));
			$this->initialState->provideInitialState('featureEnabled', $this->configService->isFeatureEnabled());
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-personalSettings');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-personalSettings');
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
