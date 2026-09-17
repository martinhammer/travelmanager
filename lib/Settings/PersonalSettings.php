<?php

declare(strict_types=1);

namespace OCA\TravelManager\Settings;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\ExtractionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConfigService $configService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ILlmService $llmService,
		private ExtractionService $extractionService,
	) {
	}

	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->initialState->provideInitialState('settings', $this->configService->getUserSettings($user->getUID()));
			$this->initialState->provideInitialState('featureEnabled', $this->configService->isFeatureEnabled());
		}
		// Same read-only diagnostics as the admin panel, minus the endpoint URL
		// unless this user is an admin: which provider is configured is useful
		// to anyone debugging their own extractions, but the URL is
		// admin-configured infrastructure and may name an internal host.
		$isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());
		$provider = $this->llmService->describeProvider();
		$this->initialState->provideInitialState('llm', [
			'provider' => $provider?->toArray($isAdmin),
			'canSeeEndpoint' => $isAdmin,
			'promptTemplate' => $this->extractionService->promptTemplate(),
		]);

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
