<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMap;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\Dto\QueueCapacity;
use OCA\TravelManager\Service\ExtractionQueue;
use OCA\TravelManager\Service\ExtractionResultHandler;
use OCA\TravelManager\Service\ExtractionService;
use OCA\TravelManager\Service\IngestionLogger;
use OCA\TravelManager\Service\QueuePlanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The I/O around QueuePlanner: claiming, scheduling and reconciling. Mocks OCP
 * types, so like IngestionServiceTest it runs only inside a server checkout; the
 * limit rules themselves are covered standalone in QueuePlannerTest.
 */
final class ExtractionQueueTest extends TestCase {
	private ConfigService&MockObject $configService;
	private ProcessedMessageMapper&MockObject $messageMapper;
	private TaskMapMapper&MockObject $taskMapMapper;
	private ILlmService&MockObject $llmService;
	private ExtractionResultHandler&MockObject $resultHandler;
	private IMemcache&MockObject $lock;
	private ExtractionQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
		$this->messageMapper = $this->createMock(ProcessedMessageMapper::class);
		$this->taskMapMapper = $this->createMock(TaskMapMapper::class);
		$this->llmService = $this->createMock(ILlmService::class);
		$this->resultHandler = $this->createMock(ExtractionResultHandler::class);
		$this->lock = $this->createMock(IMemcache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocking')->willReturn($this->lock);
		$time = $this->createMock(ITimeFactory::class);
		// A fresh object each call: the queue modifies the one it gets for the
		// hourly window, and a shared instance would leak that into claim times.
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime());
		$time->method('getTime')->willReturnCallback(static fn (): int => time());

		$this->queue = new ExtractionQueue(
			$this->configService,
			$this->messageMapper,
			$this->taskMapMapper,
			$this->llmService,
			new ExtractionService(),
			$this->resultHandler,
			new QueuePlanner(),
			$cacheFactory,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IngestionLogger::class),
		);
	}

	/**
	 * A provider, the lock free, nothing in flight, and the default limits.
	 *
	 * @param list<int> $queuedIds alice's queued row ids, oldest first
	 */
	private function ready(array $queuedIds, int $inFlight = 0, int $startedLastHour = 0): void {
		$this->lock->method('add')->willReturn(true);
		$this->llmService->method('hasProvider')->willReturn(true);
		$this->configService->method('getMaxInFlight')->willReturn(2);
		$this->configService->method('getMaxPerHour')->willReturn(60);
		$this->messageMapper->method('countByStatus')->willReturn($inFlight);
		$this->taskMapMapper->method('countCreatedSince')->willReturn($startedLastHour);
		$this->messageMapper->method('findUserIdsWithStatus')->willReturn($queuedIds === [] ? [] : ['alice']);
		$this->messageMapper->method('findIdsForUserByStatus')
			->willReturnCallback(static fn (string $userId, string $status, int $limit): array => array_slice($queuedIds, 0, $limit));
		$this->messageMapper->method('claimQueued')->willReturn(true);
		$this->messageMapper->method('find')->willReturnCallback(static function (int $id, string $userId): ProcessedMessage {
			$record = new ProcessedMessage();
			$record->setId($id);
			$record->setUserId($userId);
			$record->setMessageId('<m' . $id . '@example.com>');
			$record->setBodyText('body');
			$record->setStatus(ProcessedMessage::STATUS_PROCESSING);
			$record->setAttempts(0);
			return $record;
		});
	}

	private function pendingMap(int $taskId, string $createdAgo = '-1 hour', int $rowId = 1): TaskMap {
		$map = new TaskMap();
		$map->setId($rowId);
		$map->setTaskId($taskId);
		$map->setUserId('alice');
		$map->setMessageId('<m@example.com>');
		$map->setStatus(TaskMap::STATUS_PENDING);
		$map->setCreatedAt(new \DateTime($createdAgo));
		return $map;
	}

	private function task(int $status, ?int $endedAt = null): Task {
		$task = new Task('core:text2text', ['input' => 'prompt'], 'travelmanager', 'alice', '<m@example.com>');
		$task->setId(42);
		$task->setStatus($status);
		if ($endedAt !== null) {
			$task->setEndedAt($endedAt);
		}
		return $task;
	}

	/* ---------------------------------------------------------------- pump */

	public function testSendsOldestFirstUpToTheCap(): void {
		$this->ready([5, 6, 7]);

		$scheduled = [];
		$this->llmService->method('scheduleText2Text')
			->willReturnCallback(static function (string $prompt, string $userId, string $customId) use (&$scheduled): int {
				$scheduled[] = $customId;
				return count($scheduled);
			});
		$this->taskMapMapper->expects($this->exactly(2))->method('insert');

		$capacity = $this->queue->pump();

		// Cap of 2, nothing in flight: the two oldest go, the third waits.
		$this->assertSame(['<m5@example.com>', '<m6@example.com>'], $scheduled);
		$this->assertSame(QueueCapacity::LIMIT_IN_FLIGHT, $capacity?->limitedBy);
	}

	public function testSendsNothingWhenTheHourlyBudgetIsSpent(): void {
		$this->ready([5], 0, 60);
		$this->messageMapper->expects($this->never())->method('claimQueued');
		$this->llmService->expects($this->never())->method('scheduleText2Text');

		$this->assertSame(QueueCapacity::LIMIT_HOURLY, $this->queue->pump()?->limitedBy);
	}

	public function testLeavesTheQueueAloneWithoutAProvider(): void {
		// Every schedule would fail, turning each waiting email into a failure the
		// user must retry by hand. Queued, they go once a provider appears.
		$this->lock->method('add')->willReturn(true);
		$this->llmService->method('hasProvider')->willReturn(false);
		$this->messageMapper->expects($this->never())->method('claimQueued');

		$this->assertSame(QueueCapacity::LIMIT_NO_PROVIDER, $this->queue->pump()?->limitedBy);
	}

	public function testDoesNothingWhileAnotherPumpHoldsTheLock(): void {
		$this->lock->method('add')->willReturn(false);
		$this->messageMapper->expects($this->never())->method('claimQueued');

		$this->assertNull($this->queue->pump());
	}

	public function testReleasesOnlyItsOwnLock(): void {
		// Not ready(): that stubs add() already, and PHPUnit uses the first stub
		// configured, so the token below would never be captured.
		$this->llmService->method('hasProvider')->willReturn(false);
		$token = null;
		$this->lock->method('add')->willReturnCallback(static function (string $key, string $value) use (&$token): bool {
			$token = $value;
			return true;
		});
		$this->lock->expects($this->once())
			->method('cad')
			// By reference, not an arrow fn: that would capture $token while still null.
			->with('pump', $this->callback(static function (string $value) use (&$token): bool {
				return $value === $token;
			}));

		$this->queue->pump();
	}

	public function testAMessageClaimedByAnotherPumpIsNotSentAgain(): void {
		// Arranged by hand rather than via ready(): the point is that the
		// conditional update finds the row no longer queued.
		$this->lock->method('add')->willReturn(true);
		$this->llmService->method('hasProvider')->willReturn(true);
		$this->configService->method('getMaxInFlight')->willReturn(2);
		$this->messageMapper->method('findUserIdsWithStatus')->willReturn(['alice']);
		$this->messageMapper->method('findIdsForUserByStatus')->willReturn([5]);
		$this->messageMapper->method('claimQueued')->willReturn(false);

		$this->messageMapper->expects($this->never())->method('find');
		$this->llmService->expects($this->never())->method('scheduleText2Text');

		$this->queue->pump();
	}

	public function testSchedulingFailureMarksMessageFailed(): void {
		$this->ready([5]);
		$this->llmService->method('scheduleText2Text')->willThrowException(new \RuntimeException('no provider'));

		$this->messageMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getStatus() === ProcessedMessage::STATUS_FAILED
				&& $m->getFailureKind() === ProcessedMessage::FAILURE_SCHEDULE));
		$this->taskMapMapper->expects($this->never())->method('insert');

		$this->queue->pump();
	}

	/* ----------------------------------------------------------- reconcile */

	public function testReconcileSettlesATaskThePlatformNoLongerKnows(): void {
		$map = $this->pendingMap(42);
		$this->taskMapMapper->method('findByStatus')->willReturn([$map]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn($map);
		$this->messageMapper->method('findByStatus')->willReturn([]);
		$this->llmService->method('findTask')->willReturn(null);

		$this->resultHandler->expects($this->once())->method('handleLost')->with($map, $this->isType('string'));

		$this->queue->reconcile();
	}

	public function testReconcileLeavesARunningTaskAlone(): void {
		// A busy queue can hold a task for hours; that is not ours to cut short.
		$map = $this->pendingMap(42, '-5 hours');
		$this->taskMapMapper->method('findByStatus')->willReturn([$map]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn($map);
		$this->messageMapper->method('findByStatus')->willReturn([]);
		$this->llmService->method('findTask')->willReturn($this->task(Task::STATUS_RUNNING));

		$this->resultHandler->expects($this->never())->method('handleLost');
		$this->resultHandler->expects($this->never())->method('handleSuccess');
		$this->resultHandler->expects($this->never())->method('handleFailure');

		$this->queue->reconcile();
	}

	public function testReconcileRecoversASuccessWhoseEventWentMissing(): void {
		$map = $this->pendingMap(42);
		$this->taskMapMapper->method('findByStatus')->willReturn([$map]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn($map);
		$this->messageMapper->method('findByStatus')->willReturn([]);
		$task = $this->task(Task::STATUS_SUCCESSFUL, time() - 3600);
		$task->setOutput(['output' => '{"bookings": []}']);
		$this->llmService->method('findTask')->willReturn($task);

		$this->resultHandler->expects($this->once())
			->method('handleSuccess')
			->with(42, ['output' => '{"bookings": []}']);

		$this->queue->reconcile();
	}

	public function testReconcileWaitsForARecentlyEndedTasksOwnEvent(): void {
		// Ended a minute ago: the listener may simply not have run yet, and
		// stepping in now would race it.
		$map = $this->pendingMap(42);
		$this->taskMapMapper->method('findByStatus')->willReturn([$map]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn($map);
		$this->messageMapper->method('findByStatus')->willReturn([]);
		$this->llmService->method('findTask')->willReturn($this->task(Task::STATUS_FAILED, time() - 60));

		$this->resultHandler->expects($this->never())->method('handleFailure');

		$this->queue->reconcile();
	}

	public function testReconcileRetiresASupersededAttemptWithoutTouchingTheMessage(): void {
		$stale = $this->pendingMap(42, '-1 hour', 1);
		$newer = $this->pendingMap(43, '-5 minutes', 2);
		$this->taskMapMapper->method('findByStatus')->willReturn([$stale]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn($newer);
		$this->messageMapper->method('findByStatus')->willReturn([]);

		$this->llmService->expects($this->never())->method('findTask');
		$this->taskMapMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (TaskMap $m) => $m->getStatus() === TaskMap::STATUS_SUPERSEDED));

		$this->queue->reconcile();
	}

	public function testReconcileTreatsCouldNotAskAsNotGone(): void {
		$map = $this->pendingMap(42);
		$this->taskMapMapper->method('findByStatus')->willReturn([$map]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn($map);
		$this->messageMapper->method('findByStatus')->willReturn([]);
		$this->llmService->method('findTask')->willThrowException(new \RuntimeException('database hiccup'));

		$this->resultHandler->expects($this->never())->method('handleLost');

		$this->queue->reconcile();
	}

	public function testReconcileFailsAMessageThatNeverReachedTheModel(): void {
		$this->taskMapMapper->method('findByStatus')->willReturn([]);
		$orphan = new ProcessedMessage();
		$orphan->setUserId('alice');
		$orphan->setMessageId('<m@example.com>');
		$orphan->setStatus(ProcessedMessage::STATUS_PROCESSING);
		$orphan->setProcessedAt(new \DateTime('-1 hour'));
		$this->messageMapper->method('findByStatus')->willReturn([$orphan]);
		$this->taskMapMapper->method('findLatestForMessage')->willReturn(null);

		$this->messageMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getStatus() === ProcessedMessage::STATUS_FAILED
				&& $m->getFailureKind() === ProcessedMessage::FAILURE_SCHEDULE));

		$this->queue->reconcile();
	}
}
