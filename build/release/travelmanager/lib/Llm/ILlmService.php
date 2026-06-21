<?php

declare(strict_types=1);

namespace OCA\TravelManager\Llm;

/**
 * Abstraction over LLM access. The MVP has a single implementation backed by
 * Nextcloud's Task Processing API (platform strategy); the provider is whatever
 * the admin configured instance-wide (see V2). The interface is the seam where
 * a direct/JSON-mode backend could later be added for stricter structured
 * output (V7) without touching the pipeline.
 */
interface ILlmService {
	/**
	 * Schedule an asynchronous text2text extraction.
	 *
	 * @param string $prompt the full instruction + email text
	 * @param string $userId the user the task is run on behalf of (V4)
	 * @param string $customId correlation id carried on the task (V5)
	 * @return int the scheduled task id
	 * @throws \RuntimeException when no provider is available
	 */
	public function scheduleText2Text(string $prompt, string $userId, string $customId): int;

	/**
	 * Whether any provider is currently able to serve text2text tasks.
	 */
	public function hasProvider(): bool;

	/**
	 * Extract the generated text from a completed task's output array.
	 *
	 * @param array<array-key, mixed> $output
	 */
	public function readOutputText(array $output): ?string;
}
