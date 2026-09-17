<?php

declare(strict_types=1);

namespace OCA\TravelManager\Llm;

/**
 * What the app can say about the model an extraction is actually sent to.
 *
 * This is *current configuration*, not provenance: Task Processing records no
 * provider or model against a completed task (see OCP\TaskProcessing\Task —
 * there is no getter for either), so the only honest claim available is "this
 * is what the next extraction will use". Change the model in the AI admin
 * settings and every past booking was, silently, extracted by something else.
 * Anything that needs per-row truth has to snapshot this at schedule time.
 */
class ProviderInfo {
	public function __construct(
		/** The task type we schedule against, always core:text2text for now. */
		public readonly string $taskTypeId,
		/** Provider id, e.g. `integration_openai-text2text`. */
		public readonly string $providerId,
		/** The provider's own display name — for integration_openai this is the admin's "service name". */
		public readonly string $providerName,
		/** The model the provider defaults to, when it reports one. */
		public readonly ?string $model,
		public readonly ?int $maxTokens,
		/** Seconds; the provider's own rolling estimate, useful when tasks seem stuck. */
		public readonly int $expectedRuntime,
		/**
		 * The base URL the provider app will call. Null when the provider keeps
		 * no URL (a local model) or keeps it somewhere we do not know to look —
		 * see TaskProcessingLlmService::endpointUrl() for why this cannot come
		 * from an interface.
		 */
		public readonly ?string $endpointUrl,
	) {
	}

	/**
	 * @param bool $withEndpoint false drops the URL, for panels shown to
	 *                           non-admins: it is admin-configured infrastructure
	 *                           and may name an internal host.
	 * @return array{taskTypeId: string, providerId: string, providerName: string, model: string|null, maxTokens: int|null, expectedRuntime: int, endpointUrl: string|null}
	 */
	public function toArray(bool $withEndpoint): array {
		return [
			'taskTypeId' => $this->taskTypeId,
			'providerId' => $this->providerId,
			'providerName' => $this->providerName,
			'model' => $this->model,
			'maxTokens' => $this->maxTokens,
			'expectedRuntime' => $this->expectedRuntime,
			'endpointUrl' => $withEndpoint ? $this->endpointUrl : null,
		];
	}
}
