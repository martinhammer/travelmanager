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
		// Record a dedup row up front so re-runs before completion don't
		// re-enqueue the same message. The body is retained so the extraction can
		// be re-run later without going back to IMAP (a message may be gone from
		// the mailbox, and UIDVALIDITY may have rolled).
		$record = new ProcessedMessage();
		$record->setUserId($userId);
		$record->setMailbox($mailbox);
		$record->setMessageId($message->messageId);
		$record->setUidValidity($message->uidValidity);
		$record->setImapUid($message->uid);
		$record->setSubject($message->subject === '' ? null : $message->subject);
		// Truncated to the column width: a display label, not a parseable header.
		$record->setSender($message->from === null ? null : mb_substr($message->from, 0, 255));
		$record->setSentAt($message->date === null ? null : \DateTime::createFromImmutable($message->date));
		$record->setBodyText($message->textBody);
		$record->setStatus(ProcessedMessage::STATUS_PROCESSING);
		$record->setAttempts(0);
		$record->setProcessedAt($this->timeFactory->getDateTime());
		$this->processedMessageMapper->insert($record);

		return $this->scheduleExtraction($record, $this->describe($message));
	}

	/**
	 * Re-run the extraction for an already-ingested message, using the retained
	 * body. Deliberately bypasses the dedup check — the message is processed by
	 * definition; that is the whole point.
	 *
	 * @throws \RuntimeException when the body was not retained, so there is
	 *                           nothing to re-extract
	 */
	public function retryMessage(string $userId, int $id): void {
		$record = $this->processedMessageMapper->find($id, $userId);
		if (!$record->canRetry()) {
			throw new \RuntimeException('The email body for this message was not retained, so it cannot be re-extracted');
		}

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			'Retrying extraction (attempt ' . ($record->getAttempts() + 1) . ') for ' . $this->describeRecord($record),
		);

		$record->setStatus(ProcessedMessage::STATUS_PROCESSING);
		$record->setError(null);
		$record->setFailureKind(null);
		// The previous attempt's issues and links describe a response we are about
		// to replace.
		$record->setIssueReasons(null);
		$record->setRelatedBookingIds(null);
		// Re-running is how the user says they think it can work after all, so a
		// discard cannot survive it: the outcome of this attempt is unknown, and a
		// failure the user has not seen yet must be able to ask for attention.
		$record->setDiscarded(false);
		$record->setProcessedAt($this->timeFactory->getDateTime());
		$this->processedMessageMapper->update($record);

		$this->scheduleExtraction($record, $this->describeRecord($record));
	}

	/**
	 * Record, or take back, the user's decision to discard a ledger row.
	 *
	 * The counterpart of a booking's review state: `status` keeps saying what the
	 * pipeline observed, and this says whether the user still wants to be asked
	 * about it. Not a delete — the row is the dedup key, so removing it would only
	 * have the next mailbox read ingest the same email into the same dead end.
	 *
	 * Allowed on any row rather than only the failed ones. It is a no-op for the
	 * attention count anywhere else, and the alternative is a rule the API has to
	 * explain and the UI has to duplicate; the button is offered only where it
	 * does something.
	 */
	public function discardMessage(string $userId, int $id, bool $discarded): ProcessedMessage {
		$record = $this->processedMessageMapper->find($id, $userId);
		$record->setDiscarded($discarded);
		$this->processedMessageMapper->update($record);

		// persist, not schedule: this writes stored state and starts nothing.
		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_PERSIST,
			($discarded ? 'Discarded ' : 'Restored ') . $this->describeRecord($record),
		);

		return $record;
	}

	/**
	 * Build the prompt from a stored message and hand it to the model, recording
	 * the task correlation. Shared by first ingestion and retry, so both count
	 * attempts and report failures the same way.
	 *
	 * @param string $label human-readable identification for the activity log
	 */
	private function scheduleExtraction(ProcessedMessage $record, string $label): bool {
		$userId = $record->getUserId();
		$record->setAttempts($record->getAttempts() + 1);

		try {
			$prompt = $this->extractionService->buildPrompt($record->getBodyText() ?? '', $record->getSubject());
			$taskId = $this->llmService->scheduleText2Text($prompt, $userId, $record->getMessageId());
		} catch (\Throwable $e) {
			$this->logger->warning('Travel Manager: failed to schedule extraction for ' . $userId . ': ' . $e->getMessage());
			$this->activityLog->error(
				$userId,
				IngestionLog::STEP_SCHEDULE,
				'Failed to schedule extraction for ' . $label . ': ' . $e->getMessage(),
			);
			$record->setStatus(ProcessedMessage::STATUS_FAILED);
			$record->setFailureKind(ProcessedMessage::FAILURE_SCHEDULE);
			$record->setError($e->getMessage());
			$this->processedMessageMapper->update($record);
			return false;
		}

		$this->processedMessageMapper->update($record);

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			'Passing message to the model (task #' . $taskId . '): ' . $label,
			$prompt,
		);

		$map = new TaskMap();
		$map->setTaskId($taskId);
		$map->setUserId($userId);
		$map->setMessageId($record->getMessageId());
		$map->setStatus(TaskMap::STATUS_PENDING);
		$map->setCreatedAt($this->timeFactory->getDateTime());
		$this->taskMapMapper->insert($map);

		return true;
	}

	/** Short human-readable identification of a message for the activity log. */
	private function describe(ImapMessage $message): string {
		$subject = $message->subject === '' ? '(no subject)' : $message->subject;
		return '"' . $subject . '" ' . $message->messageId;
	}

	/** As describe(), for a message already in the database. */
	private function describeRecord(ProcessedMessage $record): string {
		return '"' . ($record->getSubject() ?? '(no subject)') . '" ' . $record->getMessageId();
	}
}
