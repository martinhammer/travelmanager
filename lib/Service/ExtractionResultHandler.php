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
use OCA\TravelManager\Service\Dto\AppliedExtraction;
use OCA\TravelManager\Service\Dto\ExtractionResult;
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
	/** Matches the activity log's context cap; a diagnostic, not an archive. */
	private const MAX_RESPONSE_CHARS = 20000;

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

		// Set before any branch below: setMessageStatus() is what persists the
		// row, so every outcome carries the response that produced it.
		$this->setLastResponse($message, $text);

		try {
			if ($text === null) {
				throw new ExtractionException('Task output contained no text');
			}
			$result = $this->extractionService->parseAndValidate($text);
			$applied = $this->bookingService->applyExtraction($userId, $map->getMessageId(), $result->bookings);
			$count = $applied->created;
			$dropped = $result->droppedCount();

			// Bookings this email matched but did not touch are always reported,
			// whatever else happened — that notice is the only signal the user
			// gets that an update exists which the MVP cannot apply.
			if ($applied->related !== []) {
				$this->activityLog->warning(
					$userId,
					IngestionLog::STEP_PERSIST,
					'Task #' . $taskId . ' relates to ' . count($applied->related) . ' booking(s) you already have — not applied',
					$applied->describeRelated(),
				);
			}

			if ($count > 0) {
				// Issues on an otherwise successful run (a repaired response, a
				// dropped leg) are still recorded on the row — they are how a
				// degrading model shows up before it starts failing outright.
				$this->setMessageStatus(
					$message,
					ProcessedMessage::STATUS_PROCESSED,
					$this->combineNotes($result->issues === [] ? null : $result->describeIssues(), $applied),
				);
				$this->activityLog->info($userId, IngestionLog::STEP_PERSIST, 'Saved ' . $count . ' draft booking(s) from task #' . $taskId);
			} elseif ($dropped > 0) {
				// The model found bookings we then refused: retry-worthy, and the
				// opposite of "this email had nothing in it".
				$this->setMessageStatus($message, ProcessedMessage::STATUS_DROPPED, $result->describeIssues());
				$this->activityLog->warning(
					$userId,
					IngestionLog::STEP_PERSIST,
					'Rejected all ' . $dropped . ' booking(s) the model returned for task #' . $taskId . ' — nothing saved',
					$this->describeIssues($map->getMessageId(), $result, $text),
				);
			} elseif ($applied->related !== []) {
				// Nothing new, but not an empty email either: everything in it
				// belongs to a booking the user already has.
				$this->setMessageStatus(
					$message,
					ProcessedMessage::STATUS_RELATED,
					$this->combineNotes($result->issues === [] ? null : $result->describeIssues(), $applied),
				);
			} else {
				$this->setMessageStatus(
					$message,
					ProcessedMessage::STATUS_NO_BOOKING,
					$result->issues === [] ? null : $result->describeIssues(),
				);
				$this->activityLog->info($userId, IngestionLog::STEP_PERSIST, 'No travel booking found in task #' . $taskId . ' — nothing saved');
			}

			// Repairs and partial losses (e.g. a flight that kept 2 of 3 legs) do
			// not change the message status, but must not pass unnoticed either.
			// The dropped branch above already logged its own issues.
			if ($result->issues !== [] && ($count > 0 || $dropped === 0)) {
				$this->activityLog->warning(
					$userId,
					IngestionLog::STEP_PERSIST,
					'Saved task #' . $taskId . ' with ' . count($result->issues) . ' extraction issue(s)',
					$this->describeIssues($map->getMessageId(), $result, $text),
				);
			}
		} catch (ExtractionException $e) {
			$this->logger->info('Travel Manager: extraction validation failed for task ' . $taskId . ': ' . $e->getMessage());
			$this->activityLog->warning(
				$userId,
				IngestionLog::STEP_PERSIST,
				'Could not parse model output for task #' . $taskId . ': ' . $e->getMessage(),
				$this->describeParseFailure($map->getMessageId(), $e->getMessage(), $text),
			);
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage(), ProcessedMessage::FAILURE_VALIDATION);
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: failed to persist extraction for task ' . $taskId . ': ' . $e->getMessage());
			$this->activityLog->error(
				$userId,
				IngestionLog::STEP_PERSIST,
				'Failed to save extraction for task #' . $taskId . ': ' . $e->getMessage(),
				$this->describeParseFailure($map->getMessageId(), (string)$e, $text),
			);
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage(), ProcessedMessage::FAILURE_VALIDATION);
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
		// A failed task may still carry partial output; it is often the clue.
		$output = $task->getOutput();
		$this->setLastResponse(
			$message,
			$output === null || $output === []
				? null
				: (string)json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		);
		$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $error, ProcessedMessage::FAILURE_PROVIDER);
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
	 * Troubleshooting block for a response that parsed but whose bookings were
	 * rejected or trimmed: what we refused and why, next to the raw response that
	 * produced it — the pair you need in front of you to tune the prompt.
	 */
	private function describeIssues(string $messageId, ExtractionResult $result, ?string $rawResponse): string {
		$lines = [
			'Kept ' . count($result->bookings) . ' booking(s); dropped ' . $result->droppedCount() . '.',
			'Source message: ' . $messageId,
			'',
			'--- Extraction issues ---',
			$result->describeIssues(),
			'',
			'--- Raw model response ---',
			$rawResponse ?? '(no text returned)',
		];
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

	private function setMessageStatus(?ProcessedMessage $message, string $status, ?string $error, ?string $failureKind = null): void {
		if ($message === null) {
			return;
		}
		$message->setStatus($status);
		$message->setError($error);
		// Cleared on any non-failure outcome so a successful retry does not leave
		// the previous attempt's failure kind behind.
		$message->setFailureKind($failureKind);
		$this->processedMessageMapper->update($message);
	}

	/**
	 * Join the extraction's own notes with the "matches a booking you already
	 * have" report, so the message row carries both.
	 */
	private function combineNotes(?string $issues, AppliedExtraction $applied): ?string {
		$parts = array_filter([
			$issues,
			$applied->related === [] ? null : "Related to bookings you already have:\n" . $applied->describeRelated(),
		]);
		return $parts === [] ? null : implode("\n\n", $parts);
	}

	/**
	 * Record what the model actually returned, so the Messages view can show the
	 * response next to the error rather than only the error. Truncated: this is a
	 * diagnostic, not an archive.
	 */
	private function setLastResponse(?ProcessedMessage $message, ?string $response): void {
		if ($message === null) {
			return;
		}
		$message->setLastResponse($response === null ? null : mb_substr($response, 0, self::MAX_RESPONSE_CHARS));
	}

	private function setTaskStatus(TaskMap $map, string $status): void {
		$map->setStatus($status);
		$this->taskMapMapper->update($map);
	}
}
