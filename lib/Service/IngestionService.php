<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMap;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapConnection;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Llm\ILlmService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Per-user ingestion: connect to the dedicated travel mailbox (read-only),
 * dedup against the app database (V6), and schedule an extraction task per new
 * message. The extraction result is handled asynchronously by the task event
 * listeners (V5). Runs in system context on behalf of $userId (V4).
 */
class IngestionService {
	public function __construct(
		private ConfigService $configService,
		private IImapClient $imapClient,
		private ILlmService $llmService,
		private ExtractionService $extractionService,
		private ProcessedMessageMapper $processedMessageMapper,
		private TaskMapMapper $taskMapMapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return int number of new messages enqueued for extraction
	 */
	public function ingestForUser(string $userId): int {
		$settings = $this->configService->getUserSettings($userId);
		if (!$settings->isConfigured()) {
			$this->logger->debug('Travel Manager: user ' . $userId . ' not fully configured, skipping');
			return 0;
		}

		$password = $this->configService->getImapPassword($userId);
		if ($password === null) {
			return 0;
		}

		$connection = new ImapConnection(
			$settings->imapHost,
			$settings->imapPort,
			$settings->imapSecurity,
			$settings->imapUser,
			$password,
			$settings->mailbox,
		);

		$messages = $this->imapClient->fetchRecent($connection, $this->configService->getRateLimitPerRun());

		$enqueued = 0;
		foreach ($messages as $message) {
			if ($this->processedMessageMapper->isProcessed($userId, $message->messageId)) {
				continue;
			}
			if ($this->enqueue($userId, $settings->mailbox, $message)) {
				$enqueued++;
			}
		}
		return $enqueued;
	}

	private function enqueue(string $userId, string $mailbox, ImapMessage $message): bool {
		$now = $this->timeFactory->getDateTime();

		// Record a dedup row up front so re-runs before completion don't
		// re-enqueue the same message.
		$record = new ProcessedMessage();
		$record->setUserId($userId);
		$record->setMailbox($mailbox);
		$record->setMessageId($message->messageId);
		$record->setUidValidity($message->uidValidity);
		$record->setImapUid($message->uid);
		$record->setStatus(ProcessedMessage::STATUS_PROCESSING);
		$record->setProcessedAt($now);
		$this->processedMessageMapper->insert($record);

		try {
			$prompt = $this->extractionService->buildPrompt($message->textBody, $message->subject);
			$taskId = $this->llmService->scheduleText2Text($prompt, $userId, $message->messageId);
		} catch (\Throwable $e) {
			$this->logger->warning('Travel Manager: failed to schedule extraction for ' . $userId . ': ' . $e->getMessage());
			$record->setStatus(ProcessedMessage::STATUS_FAILED);
			$record->setError($e->getMessage());
			$this->processedMessageMapper->update($record);
			return false;
		}

		$map = new TaskMap();
		$map->setTaskId($taskId);
		$map->setUserId($userId);
		$map->setMessageId($message->messageId);
		$map->setStatus(TaskMap::STATUS_PENDING);
		$map->setCreatedAt($now);
		$this->taskMapMapper->insert($map);

		return true;
	}
}
