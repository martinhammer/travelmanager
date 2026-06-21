<?php

declare(strict_types=1);

namespace OCA\TravelManager\Llm;

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

	public function __construct(
		private IManager $taskProcessingManager,
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
