<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\IngestionLog;
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
		private IngestionLogger $activityLog,
	) {
	}

	/**
	 * @return int number of new messages enqueued for extraction
	 */
	public function ingestForUser(string $userId): int {
		$settings = $this->configService->getUserSettings($userId);
		if (!$settings->isConnectable()) {
			$this->logger->debug('Travel Manager: user ' . $userId . ' has no usable IMAP connection, skipping');
			$this->activityLog->warning(
				$userId,
				IngestionLog::STEP_CONNECT,
				'Mailbox connection is not fully configured — set the IMAP host, username, app password and a mailbox/folder (e.g. INBOX), then save',
			);
			return 0;
		}

		$password = $this->configService->getImapPassword($userId);
		if ($password === null) {
			$this->activityLog->warning($userId, IngestionLog::STEP_CONNECT, 'No IMAP password stored — cannot connect');
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

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_CONNECT,
			'Connecting to ' . $settings->imapUser . '@' . $settings->imapHost . ':' . $settings->imapPort
				. ' (' . $settings->imapSecurity . ') mailbox "' . $settings->mailbox . '"',
		);

		try {
			$messages = $this->imapClient->fetchRecent($connection, $this->configService->getRateLimitPerRun());
		} catch (\Throwable $e) {
			$this->activityLog->error($userId, IngestionLog::STEP_FETCH, 'Failed to read mailbox: ' . $e->getMessage());
			throw $e;
		}

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_FETCH,
			'Fetched ' . count($messages) . ' recent message(s) from the mailbox',
		);

		$enqueued = 0;
		foreach ($messages as $message) {
			if ($this->processedMessageMapper->isProcessed($userId, $message->messageId)) {
				$this->activityLog->info(
					$userId,
					IngestionLog::STEP_DEDUP,
					'Skipping already-processed message: ' . $this->describe($message),
				);
				continue;
			}
			if ($this->enqueue($userId, $settings->mailbox, $message)) {
				$enqueued++;
			}
		}

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			'Scheduled ' . $enqueued . ' new message(s) for extraction; awaiting LLM response(s)',
		);
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
			$this->activityLog->error(
				$userId,
				IngestionLog::STEP_SCHEDULE,
				'Failed to schedule extraction for ' . $this->describe($message) . ': ' . $e->getMessage(),
			);
			$record->setStatus(ProcessedMessage::STATUS_FAILED);
			$record->setError($e->getMessage());
			$this->processedMessageMapper->update($record);
			return false;
		}

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			'Passing message to the model (task #' . $taskId . '): ' . $this->describe($message),
			$prompt,
		);

		$map = new TaskMap();
		$map->setTaskId($taskId);
		$map->setUserId($userId);
		$map->setMessageId($message->messageId);
		$map->setStatus(TaskMap::STATUS_PENDING);
		$map->setCreatedAt($now);
		$this->taskMapMapper->insert($map);

		return true;
	}

	/** Short human-readable identification of a message for the activity log. */
	private function describe(ImapMessage $message): string {
		$subject = $message->subject === '' ? '(no subject)' : $message->subject;
		return '"' . $subject . '" ' . $message->messageId;
	}
}
