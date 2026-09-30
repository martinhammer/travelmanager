<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMap;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Service\Dto\QueueCapacity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Releases queued messages to the model, and keeps the in-flight set honest.
 *
 * Reading the mailbox and sending to the model are separate steps: ingestion
 * stores new mail as `queued`, and this sends it on under two instance-wide
 * limits — how many of our tasks may wait on the model at once, and how many we
 * may start per rolling hour. {@see QueuePlanner} holds the rules; this class
 * does the I/O around them.
 *
 * **When it runs.** This code has no clock of its own: it runs on the
 * dispatcher's cron tick, after every task completes (the listeners), and when a
 * user acts (Read mailbox now, Retry). Completions are what keep a backlog
 * moving between ticks; the tick is what resumes it after an hourly budget
 * frees up. It never sleeps waiting for a slot — sleeping in a worker is exactly
 * what blocks every other app's AI tasks.
 *
 * **Races.** A tick and a completion listener can pump at the same moment.
 * `claimQueued` is a conditional update, so a message is never sent twice. The
 * limits themselves are guarded by a lock in the locking cache; on an instance
 * with no locking cache configured that lock is a no-op (NullCache accepts every
 * `add`), and the cap degrades to a soft one that concurrent pumps can overshoot
 * by a few. That is acceptable for load protection, and it is why nothing here
 * treats the cap as an invariant.
 */
class ExtractionQueue {
	private const LOCK_KEY = 'pump';
	/** Outlives any one pump's burst of scheduling; a crashed holder expires. */
	private const LOCK_TTL = 60;
	/**
	 * How long an ended task may go unreported before we step in. The completion
	 * event fires the moment a task ends, so ten minutes of silence means it went
	 * missing — and stepping in sooner would race a listener that is merely slow.
	 */
	private const GRACE_SECONDS = 600;
	/** The cap keeps the in-flight set small; this only bounds a pathological backlog. */
	private const RECONCILE_BATCH = 100;

	public function __construct(
		private ConfigService $configService,
		private ProcessedMessageMapper $messageMapper,
		private TaskMapMapper $taskMapMapper,
		private ILlmService $llmService,
		private ExtractionService $extractionService,
		private ExtractionResultHandler $resultHandler,
		private QueuePlanner $planner,
		private ICacheFactory $cacheFactory,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private IngestionLogger $activityLog,
	) {
	}

	/**
	 * Send as many queued messages to the model as the limits allow right now.
	 *
	 * Deliberately does **not** check the feature flag: the callers do. The
	 * dispatcher and the task listeners are automatic work and are gated on it;
	 * Read mailbox now and Retry are a user asking explicitly, and have always
	 * worked with the pipeline switched off.
	 *
	 * @return QueueCapacity|null what it worked with — callers use it to explain a
	 *                            wait — or null when another pump already held the lock
	 */
	public function pump(): ?QueueCapacity {
		$lock = $this->cacheFactory->createLocking('travelmanager');
		// A token, so we only ever release our own lock: if this pump outlived the
		// TTL, the key may now belong to another one.
		$token = bin2hex(random_bytes(8));
		if (!$lock->add(self::LOCK_KEY, $token, self::LOCK_TTL)) {
			return null;
		}
		try {
			return $this->drain();
		} finally {
			$lock->cad(self::LOCK_KEY, $token);
		}
	}

	/**
	 * Settle tasks that will never report back, so they stop holding slots.
	 *
	 * Without this a cap turns one lost task from a stuck row into a stalled
	 * pipeline. Runs on the dispatcher tick only: it asks Task Processing about
	 * each pending task, which is not work to repeat after every completion.
	 */
	public function reconcile(): void {
		$now = $this->timeFactory->getTime();

		foreach ($this->taskMapMapper->findByStatus(TaskMap::STATUS_PENDING, self::RECONCILE_BATCH) as $map) {
			try {
				$this->reconcileTask($map, $now);
			} catch (\Throwable $e) {
				// "Could not ask" is never "gone": leave it for the next tick.
				$this->logger->warning('Travel Manager: could not check task #' . $map->getTaskId() . ': ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		// Second pass, after the first may have settled some: rows marked sent
		// that have no live task behind them at all.
		foreach ($this->messageMapper->findByStatus(ProcessedMessage::STATUS_PROCESSING, self::RECONCILE_BATCH) as $message) {
			try {
				$this->reconcileOrphan($message, $now);
			} catch (\Throwable $e) {
				$this->logger->warning('Travel Manager: could not check message ' . $message->getMessageId() . ': ' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}

	private function drain(): QueueCapacity {
		// Checked before claiming anything: with no provider every schedule
		// fails, and draining the queue would turn each waiting email into a
		// failure the user then has to retry by hand. Left queued, they simply go
		// once a provider is configured.
		if (!$this->llmService->hasProvider()) {
			return new QueueCapacity(0, QueueCapacity::LIMIT_NO_PROVIDER);
		}

		$hourAgo = $this->timeFactory->getDateTime();
		$hourAgo->modify('-1 hour');
		$capacity = $this->planner->capacity(
			$this->messageMapper->countByStatus(ProcessedMessage::STATUS_PROCESSING),
			$this->configService->getMaxInFlight(),
			$this->taskMapMapper->countCreatedSince($hourAgo),
			$this->configService->getMaxPerHour(),
		);
		if ($capacity->slots === 0) {
			return $capacity;
		}

		// No user can be given more than every slot, so that is all we fetch each.
		$queued = [];
		foreach ($this->messageMapper->findUserIdsWithStatus(ProcessedMessage::STATUS_QUEUED) as $userId) {
			$queued[$userId] = $this->messageMapper->findIdsForUserByStatus($userId, ProcessedMessage::STATUS_QUEUED, $capacity->slots);
		}

		foreach ($this->planner->pick($queued, $capacity->slots) as $pick) {
			$this->send($pick->userId, $pick->id);
		}
		return $capacity;
	}

	private function send(string $userId, int $id): void {
		if (!$this->messageMapper->claimQueued($id, $userId, $this->timeFactory->getDateTime())) {
			return; // Another pump claimed it first.
		}
		try {
			$record = $this->messageMapper->find($id, $userId);
		} catch (DoesNotExistException) {
			return; // Wiped between the claim and the read.
		}
		$this->schedule($record);
	}

	/**
	 * Build the prompt from a stored message and hand it to the model, recording
	 * the task correlation. The one place a task is created, for first attempts
	 * and retries alike, so both count attempts and report failures the same way.
	 */
	private function schedule(ProcessedMessage $record): void {
		$userId = $record->getUserId();
		$label = $this->describe($record);
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
			$this->messageMapper->update($record);
			return;
		}

		$this->messageMapper->update($record);

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
	}

	private function reconcileTask(TaskMap $map, int $now): void {
		$createdAt = $map->getCreatedAt()?->getTimestamp() ?? 0;
		if ($now - $createdAt < self::GRACE_SECONDS) {
			return; // Too young to judge.
		}

		// An older attempt left pending — its message was retried since — must not
		// get to overwrite the newer attempt's outcome, whatever became of it.
		$latest = $this->taskMapMapper->findLatestForMessage($map->getUserId(), $map->getMessageId());
		if ($latest !== null && $latest->getId() !== $map->getId()) {
			$map->setStatus(TaskMap::STATUS_SUPERSEDED);
			$this->taskMapMapper->update($map);
			return;
		}

		$task = $this->llmService->findTask($map->getTaskId());
		if ($task === null) {
			$this->resultHandler->handleLost(
				$map,
				'Task Processing no longer has this extraction task, so its result will never arrive. Retry to run it again.',
			);
			return;
		}

		$status = $task->getStatus();
		if ($status !== Task::STATUS_SUCCESSFUL && $status !== Task::STATUS_FAILED && $status !== Task::STATUS_CANCELLED) {
			// Scheduled, running or unknown: genuinely still in flight. A task can
			// wait a long time in a busy queue, and that is not ours to cut short.
			return;
		}

		$endedAt = $task->getEndedAt() ?? $createdAt;
		if ($now - $endedAt < self::GRACE_SECONDS) {
			return; // The event may simply not have been handled yet.
		}

		$this->logger->info('Travel Manager: task #' . $map->getTaskId() . ' ended without its event reaching us; settling it now');
		if ($status === Task::STATUS_SUCCESSFUL) {
			$this->resultHandler->handleSuccess($map->getTaskId(), $task->getOutput() ?? []);
		} else {
			$this->resultHandler->handleFailure(
				$task,
				$task->getErrorMessage() ?? ($status === Task::STATUS_CANCELLED ? 'The task was cancelled' : 'The task failed without an error message'),
			);
		}
	}

	/**
	 * A row marked sent with no pending task behind it can never leave
	 * `processing` on its own: either it was claimed and the schedule never
	 * completed (a crash in between), or its task settled without the row being
	 * updated. Either way it holds a slot for ever.
	 */
	private function reconcileOrphan(ProcessedMessage $message, int $now): void {
		$sentAt = $message->getProcessedAt()?->getTimestamp() ?? 0;
		if ($now - $sentAt < self::GRACE_SECONDS) {
			return;
		}

		$latest = $this->taskMapMapper->findLatestForMessage($message->getUserId(), $message->getMessageId());
		if ($latest !== null && $latest->getStatus() === TaskMap::STATUS_PENDING) {
			return; // Has a live task; reconcileTask looks after it.
		}

		if ($latest === null) {
			$kind = ProcessedMessage::FAILURE_SCHEDULE;
			$error = 'This message was picked up for extraction but never reached the model. Retry to run it again.';
		} else {
			$kind = ProcessedMessage::FAILURE_PROVIDER;
			$error = 'The extraction finished, but its result was never recorded against this message. Retry to run it again.';
		}

		$message->setStatus(ProcessedMessage::STATUS_FAILED);
		$message->setFailureKind($kind);
		$message->setError($error);
		$this->messageMapper->update($message);

		$this->activityLog->error($message->getUserId(), IngestionLog::STEP_SCHEDULE, $error . ' ' . $this->describe($message));
	}

	/** Short human-readable identification of a message for the activity log. */
	private function describe(ProcessedMessage $record): string {
		return '"' . ($record->getSubject() ?? '(no subject)') . '" ' . $record->getMessageId();
	}
}
