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
			$this->activityLog->warning($userId, IngestionLog::STEP_PERSIST, 'Could not parse model output for task #' . $taskId . ': ' . $e->getMessage());
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: failed to persist extraction for task ' . $taskId . ': ' . $e->getMessage());
			$this->activityLog->error($userId, IngestionLog::STEP_PERSIST, 'Failed to save extraction for task #' . $taskId . ': ' . $e->getMessage());
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage());
		}

		$this->setTaskStatus($map, TaskMap::STATUS_COMPLETED);
	}

	public function handleFailure(int $taskId, string $error): void {
		$map = $this->taskMapMapper->findByTaskId($taskId);
		if ($map === null) {
			return;
		}
		$message = $this->processedMessageMapper->findByMessageId($map->getUserId(), $map->getMessageId());
		$this->activityLog->error($map->getUserId(), IngestionLog::STEP_LLM_RESPONSE, 'Extraction task #' . $taskId . ' failed: ' . $error);
		$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $error);
		$this->setTaskStatus($map, TaskMap::STATUS_FAILED);
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
