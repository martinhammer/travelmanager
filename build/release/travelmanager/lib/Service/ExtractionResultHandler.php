<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

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

		$text = $this->llmService->readOutputText($output);
		$message = $this->processedMessageMapper->findByMessageId($map->getUserId(), $map->getMessageId());

		try {
			if ($text === null) {
				throw new ExtractionException('Task output contained no text');
			}
			$bookings = $this->extractionService->parseAndValidate($text);
			$count = $this->bookingService->applyExtraction($map->getUserId(), $map->getMessageId(), $bookings);
			$this->setMessageStatus(
				$message,
				$count > 0 ? ProcessedMessage::STATUS_PROCESSED : ProcessedMessage::STATUS_NO_BOOKING,
				null,
			);
		} catch (ExtractionException $e) {
			$this->logger->info('Travel Manager: extraction validation failed for task ' . $taskId . ': ' . $e->getMessage());
			$this->setMessageStatus($message, ProcessedMessage::STATUS_FAILED, $e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: failed to persist extraction for task ' . $taskId . ': ' . $e->getMessage());
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
