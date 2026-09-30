<?php

declare(strict_types=1);

namespace OCA\TravelManager\Settings;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\ExtractionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConfigService $configService,
		private ILlmService $llmService,
		private ExtractionService $extractionService,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('adminSettings', [
			'enabled' => $this->configService->isFeatureEnabled(),
			'fetchPerRun' => $this->configService->getFetchPerRun(),
			'maxInFlight' => $this->configService->getMaxInFlight(),
			'maxPerHour' => $this->configService->getMaxPerHour(),
		]);
		// Read-only diagnostics: which model extractions actually go to, and the
		// instructions they are sent with. The admin sees the endpoint URL in
		// full — it is their own configuration.
		$provider = $this->llmService->describeProvider();
		$this->initialState->provideInitialState('llm', [
			'provider' => $provider?->toArray(true),
			'canSeeEndpoint' => true,
			'promptTemplate' => $this->extractionService->promptTemplate(),
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
