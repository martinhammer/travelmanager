<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMap;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Exception\ExtractionException;
use OCA\TravelManager\Llm\ILlmService;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Maps a completed Task Processing task back to its source message/user (V5),
 * validates the LLM output and persists the resulting draft bookings.
 *
 * Runs in the cron / AI-worker process with no request context, so all
 * correlation comes from the persisted TaskMap.
 */
class ExtractionResultHandler {
	public function __construct(
		private TaskMapMapper $taskMapMapper,
		private ProcessedMessageMapper $processedMessageMapper,
		private ExtractionService $extractionService,
		private BookingService $bookingService,
		private ILlmService $llmService,
		private LoggerInterface $logger,
		private IngestionLogger $activityLog,
	) {
	}

	/**
	 * @param array<array-key, mixed> $output
	 */
	public function handleSuccess(int $taskId, array $output): void {
		$map = $this->taskMapMapper->findByTaskId($taskId);
		if ($map === null) {
			return; // Not one of our tasks.
		}

		$userId = $map->getUserId();
		$text = $this->llmService->readOutputText($output);
		$message = $this->processedMessageMapper->findByMessageId($userId, $map->getMessageId());

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_LLM_RESPONSE,
			'Model response received for task #' . $taskId . ' (' . ($text === null ? 'empty' : mb_strlen($text) . ' chars') . ')',
			$text,
		);

		try {
			if ($text === null) {
				throw new ExtractionException('Task output contained no text');
			}
			$bookings = $this->extractionService->parseAndValidate($text);
			$count = $this->bookingService->applyExtraction($userId, $map->getMessageId(), $bookings);
			$this->setMessageStatus(
				$message,
				$count > 0 ? ProcessedMessage::STATUS_PROCESSED : ProcessedMessage::STATUS_NO_BOOKING,
				null,
			);
			if ($count > 0) {
				$this->activityLog->info($userId, IngestionLog::STEP_PERSIST, 'Saved ' . $count . ' draft booking(s) from task #' . $taskId);
			} else {
				$this->activityLog->info($userId, IngestionLog::STEP_PERSIST, 'No travel booking found in task #' . $taskId . ' — nothing saved');
			}
		} catch (ExtractionException $e) {
			$this->logger->info('Travel Manager: extraction validation failed for task ' . $taskId . ': ' . $e->getMessage());
			$this->activityLog->warning(
				$userId,
				IngestionLog::STEP_PERSIST,
				'Could not parse model output for task #' . $taskId . ': ' . $e->getMessage(),
				$this->describeParseFailure($map->getMessageId(), $e->getMessage(), $text),
			);
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: failed to persist extraction for task ' . $taskId . ': ' . $e->getMessage());
			$this->activityLog->error(
				$userId,
				IngestionLog::STEP_PERSIST,
				'Failed to save extraction for task #' . $taskId . ': ' . $e->getMessage(),
				$this->describeParseFailure($map->getMessageId(), (string)$e, $text),
			);
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage());
		}

		$this->setTaskStatus($map, TaskMap::STATUS_COMPLETED);
	}

	public function handleFailure(Task $task, string $error): void {
		$taskId = (int)$task->getId();
		$map = $this->taskMapMapper->findByTaskId($taskId);
		if ($map === null) {
			return;
		}
		$message = $this->processedMessageMapper->findByMessageId($map->getUserId(), $map->getMessageId());
		$this->activityLog->error(
			$map->getUserId(),
			IngestionLog::STEP_LLM_RESPONSE,
			'Extraction task #' . $taskId . ' failed: ' . $error,
			$this->describeFailedTask($task, $error),
		);
		$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $error);
		$this->setTaskStatus($map, TaskMap::STATUS_FAILED);
	}

	/**
	 * Build a self-contained, human-readable troubleshooting block for a failed
	 * Task Processing task: the provider error plus everything we sent and got
	 * back (prompt, task metadata, any partial output). Stored in the activity
	 * log's context so the whole failure can be diagnosed from the UI.
	 */
	private function describeFailedTask(Task $task, string $error): string {
		$lines = [
			'Provider error: ' . $error,
			'Task ID: ' . ($task->getId() ?? '—'),
			'Task type: ' . $task->getTaskTypeId(),
			'Status: ' . Task::statusToString($task->getStatus()),
			'Source message: ' . ($task->getCustomId() ?? '—'),
		];

		$scheduledAt = $task->getScheduledAt();
		$endedAt = $task->getEndedAt();
		if ($scheduledAt !== null) {
			$lines[] = 'Scheduled at: ' . $this->formatTimestamp($scheduledAt);
		}
		if ($endedAt !== null) {
			$lines[] = 'Ended at: ' . $this->formatTimestamp($endedAt);
		}

		$input = $task->getInput();
		if (isset($input['input']) && is_string($input['input'])) {
			$lines[] = '';
			$lines[] = '--- Prompt sent to provider ---';
			$lines[] = $input['input'];
		}

		$output = $task->getOutput();
		if ($output !== null && $output !== []) {
			$lines[] = '';
			$lines[] = '--- Partial/raw provider output ---';
			$lines[] = (string)json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		return implode("\n", $lines);
	}

	/**
	 * Troubleshooting block for a task that returned text but could not be parsed
	 * into valid bookings: the validation error next to the raw model response,
	 * so the failure is diagnosable without cross-referencing other log rows.
	 */
	private function describeParseFailure(string $messageId, string $error, ?string $rawResponse): string {
		$lines = [
			'Validation error: ' . $error,
			'Source message: ' . $messageId,
			'',
			'--- Raw model response ---',
			$rawResponse ?? '(no text returned)',
		];
		return implode("\n", $lines);
	}

	private function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
	}

	private function setMessageStatus(?ProcessedMessage $message, string $status, ?string $error): void {
		if ($message === null) {
			return;
		}
		$message->setStatus($status);
		$message->setError($error);
		$this->processedMessageMapper->update($message);
	}

	private function setTaskStatus(TaskMap $map, string $status): void {
		$map->setStatus($status);
		$this->taskMapMapper->update($map);
	}
}
