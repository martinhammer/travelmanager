<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapConnection;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Service\Dto\QueueCapacity;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Per-user ingestion: connect to the dedicated travel mailbox (read-only),
 * dedup against the app database (V6), and **queue** each new message for
 * extraction. It does not send anything to the model itself: {@see ExtractionQueue}
 * releases queued messages under the admin's in-flight cap and hourly budget, so
 * how much mail a run reads never decides how hard we lean on the provider. The
 * extraction result is handled asynchronously by the task event listeners (V5).
 * Runs in system context on behalf of $userId (V4).
 */
class IngestionService {
	public function __construct(
		private ConfigService $configService,
		private IImapClient $imapClient,
		private ProcessedMessageMapper $processedMessageMapper,
		private ExtractionQueue $queue,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private IngestionLogger $activityLog,
	) {
	}

	/**
	 * Read the mailbox, queue what is new, then give the queue a chance to send.
	 *
	 * @return int number of new messages queued for extraction
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
			$messages = $this->imapClient->fetchRecent($connection, $this->configService->getFetchPerRun());
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
			'Queued ' . $enqueued . ' new message(s) for extraction',
		);
		$this->release($userId);
		return $enqueued;
	}

	private function enqueue(string $userId, string $mailbox, ImapMessage $message): bool {
		// The row is the dedup record *and* the queue entry: written before anything
		// is sent, so a re-run never ingests the same message twice however long it
		// waits. The body is retained because the queue sends from it — and so the
		// extraction can be re-run later without going back to IMAP (a message may
		// be gone from the mailbox, and UIDVALIDITY may have rolled).
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
		$record->setStatus(ProcessedMessage::STATUS_QUEUED);
		$record->setAttempts(0);
		$record->setProcessedAt($this->timeFactory->getDateTime());
		$this->processedMessageMapper->insert($record);

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			'Queued for extraction: ' . $this->describe($message),
		);
		return true;
	}

	/**
	 * Re-run the extraction for an already-ingested message, using the retained
	 * body. Deliberately bypasses the dedup check — the message is processed by
	 * definition; that is the whole point.
	 *
	 * Goes through the queue like a first attempt: a retry is a real request to
	 * the provider, and counts against the same limits. It is sent at once when a
	 * slot is free, and waits its turn — visibly, as `queued` — when not.
	 *
	 * @throws \RuntimeException when the body was not retained, so there is
	 *                           nothing to re-extract, or when the message is
	 *                           already waiting for the model
	 */
	public function retryMessage(string $userId, int $id): void {
		$record = $this->processedMessageMapper->find($id, $userId);
		if (!$record->canRetry()) {
			throw new \RuntimeException('The email body for this message was not retained, so it cannot be re-extracted');
		}
		// A second attempt while the first is still out would put two tasks in
		// flight for one email, and whichever answered last would win.
		if ($record->getStatus() === ProcessedMessage::STATUS_QUEUED || $record->getStatus() === ProcessedMessage::STATUS_PROCESSING) {
			throw new \RuntimeException('This message is already waiting for the model');
		}

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			'Queued for retry (attempt ' . ($record->getAttempts() + 1) . '): ' . $this->describeRecord($record),
		);

		$record->setStatus(ProcessedMessage::STATUS_QUEUED);
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

		$this->release($userId);
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
	 * Give the queue a chance to send, then tell this user about anything of
	 * theirs still waiting — and *why*, since "queued" with no reason reads like
	 * something stuck. Silent when nothing waits.
	 */
	private function release(string $userId): void {
		$capacity = $this->queue->pump();
		$waiting = $this->processedMessageMapper->countForUserByStatus($userId, ProcessedMessage::STATUS_QUEUED);
		if ($waiting === 0) {
			return;
		}
		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_SCHEDULE,
			$waiting . ' message(s) waiting to be sent to the model: ' . $this->describeWait($capacity),
		);
	}

	private function describeWait(?QueueCapacity $capacity): string {
		if ($capacity === null) {
			return 'another run is sending queued messages right now';
		}
		return match ($capacity->limitedBy) {
			QueueCapacity::LIMIT_HOURLY => 'the limit of ' . $this->configService->getMaxPerHour()
				. ' extractions per hour has been reached; they continue as the hour rolls on',
			QueueCapacity::LIMIT_NO_PROVIDER => 'no AI provider is available for text processing; they will be sent once one is configured',
			default => 'at most ' . $this->configService->getMaxInFlight()
				. ' extraction(s) wait on the model at a time; they go as earlier ones finish',
		};
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
