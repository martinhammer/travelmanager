<?php

declare(strict_types=1);

namespace OCA\TravelManager\Llm;

use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Log\LoggerInterface;

/**
 * Task Processing (platform strategy) implementation of {@see ILlmService}.
 *
 * Schedules core:text2text tasks attributed to the originating user; the
 * result is delivered asynchronously via TaskSuccessfulEvent (see V5).
 */
class TaskProcessingLlmService implements ILlmService {
	private const APP_ID = 'travelmanager';

	/**
	 * Where a provider app keeps the base URL it calls, keyed by app id.
	 *
	 * Task Processing deliberately hides the transport — no OCP interface
	 * reports a URL — so reading the provider app's own admin config is the
	 * only way to answer "where is my email actually going?". That makes these
	 * key names a private contract of someone else's app: an unknown provider
	 * (and a local model, which has no endpoint at all) reports nothing rather
	 * than guessing, and a renamed key degrades to the same nothing.
	 */
	private const ENDPOINT_KEYS = [
		'integration_openai' => 'url',
	];

	public function __construct(
		private IManager $taskProcessingManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function hasProvider(): bool {
		try {
			return array_key_exists(TextToText::ID, $this->taskProcessingManager->getAvailableTaskTypes());
		} catch (\Throwable $e) {
			$this->logger->warning('Could not query Task Processing task types: ' . $e->getMessage());
			return false;
		}
	}

	public function describeProvider(): ?ProviderInfo {
		try {
			$provider = $this->taskProcessingManager->getPreferredProvider(TextToText::ID);
			// The model is not a separate lookup: a provider declares its own
			// defaults for the optional inputs, and since we never pass a
			// `model` input ourselves, the default it reports is literally what
			// will run. integration_openai fills it from the admin's default
			// completion model.
			$defaults = $provider->getOptionalInputShapeDefaults();
			$model = isset($defaults['model']) ? trim((string)$defaults['model']) : '';
			$maxTokens = isset($defaults['max_tokens']) && is_numeric($defaults['max_tokens'])
				? (int)$defaults['max_tokens']
				: null;
			$providerId = $provider->getId();

			return new ProviderInfo(
				TextToText::ID,
				$providerId,
				$provider->getName(),
				$model === '' ? null : $model,
				$maxTokens,
				$provider->getExpectedRuntime(),
				$this->endpointUrl($providerId),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not describe the Task Processing provider: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * The base URL the given provider's app will call, when we know where it
	 * keeps it. See ENDPOINT_KEYS for why this is a lookup table rather than an
	 * interface call.
	 */
	private function endpointUrl(string $providerId): ?string {
		// Provider ids are `<app id>-<something>` by convention across every
		// provider shipped today (integration_openai-text2text, assistant-…).
		$appId = explode('-', $providerId)[0];
		$key = self::ENDPOINT_KEYS[$appId] ?? null;
		if ($key === null) {
			return null;
		}

		try {
			// Never read a key the owning app marked sensitive. The API key
			// lives in the same namespace under a name we do not control, so a
			// rename upstream must degrade to "not shown" and never to a
			// credential printed on a settings page.
			if ($this->appConfig->isSensitive($appId, $key, null)) {
				return null;
			}
			$url = trim($this->appConfig->getValueString($appId, $key, '', $this->appConfig->isLazy($appId, $key)));
		} catch (AppConfigUnknownKeyException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->debug('Could not read the endpoint URL of ' . $appId . ': ' . $e->getMessage());
			return null;
		}

		return $url === '' ? null : $url;
	}

	public function scheduleText2Text(string $prompt, string $userId, string $customId): int {
		if (!$this->hasProvider()) {
			throw new \RuntimeException('No Task Processing provider available for ' . TextToText::ID);
		}

		$task = new Task(
			TextToText::ID,
			['input' => $prompt],
			self::APP_ID,
			$userId,
			$customId,
		);

		try {
			$this->taskProcessingManager->scheduleTask($task);
		} catch (TaskProcessingException $e) {
			throw new \RuntimeException('Failed to schedule extraction task: ' . $e->getMessage(), 0, $e);
		}

		$id = $task->getId();
		if ($id === null) {
			throw new \RuntimeException('Scheduled extraction task has no id');
		}
		return $id;
	}

	public function readOutputText(array $output): ?string {
		return isset($output['output']) && is_string($output['output']) ? $output['output'] : null;
	}
}
