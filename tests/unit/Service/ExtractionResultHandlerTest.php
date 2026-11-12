<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMap;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Service\BookingService;
use OCA\TravelManager\Service\Dto\AppliedExtraction;
use OCA\TravelManager\Service\ExtractionResultHandler;
use OCA\TravelManager\Service\ExtractionService;
use OCA\TravelManager\Service\IngestionLogger;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Settling a task: the listener and the reconciler can both arrive at one, so
 * only the caller that moves it out of `pending` may write anything.
 */
final class ExtractionResultHandlerTest extends TestCase {
	private const RESPONSE = '{"bookings": [{"type": "flight", "details": {"segments": [{"departureLocal": "2026-07-15T09:30:00"}]}}]}';

	private TaskMapMapper&MockObject $taskMapMapper;
	private ProcessedMessageMapper&MockObject $messageMapper;
	private BookingService&MockObject $bookingService;
	private ExtractionResultHandler $handler;

	protected function setUp(): void {
		$this->taskMapMapper = $this->createMock(TaskMapMapper::class);
		$this->messageMapper = $this->createMock(ProcessedMessageMapper::class);
		$this->bookingService = $this->createMock(BookingService::class);
		$llm = $this->createMock(ILlmService::class);
		$llm->method('readOutputText')->willReturn(self::RESPONSE);

		$message = new ProcessedMessage();
		$message->setUserId('alice');
		$message->setMessageId('<m@example.com>');
		$message->setStatus(ProcessedMessage::STATUS_PROCESSING);
		$this->messageMapper->method('findByMessageId')->willReturn($message);

		$this->handler = new ExtractionResultHandler(
			$this->taskMapMapper,
			$this->messageMapper,
			new ExtractionService(),
			$this->bookingService,
			$llm,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IngestionLogger::class),
		);
	}

	private function pendingMap(): TaskMap {
		$map = new TaskMap();
		$map->setId(5);
		$map->setTaskId(42);
		$map->setUserId('alice');
		$map->setMessageId('<m@example.com>');
		$map->setStatus(TaskMap::STATUS_PENDING);
		return $map;
	}

	private function failedTask(): Task {
		$task = new Task('core:text2text', ['input' => 'prompt'], 'travelmanager', 'alice', '<m@example.com>');
		$task->setId(42);
		return $task;
	}

	public function testATaskIsClaimedBeforeItsBookingsAreWritten(): void {
		// Settled last, a throw after the bookings committed left the task
		// pending, and the reconciler applied it again: the second pass matched
		// the bookings just written and rewrote the row as "related".
		$this->taskMapMapper->method('findByTaskId')->willReturn($this->pendingMap());
		$order = [];
		$this->taskMapMapper->expects($this->once())
			->method('settle')
			->with(5, TaskMap::STATUS_COMPLETED)
			->willReturnCallback(function () use (&$order): bool {
				$order[] = 'settle';
				return true;
			});
		$this->bookingService->expects($this->once())
			->method('applyExtraction')
			->willReturnCallback(function () use (&$order): AppliedExtraction {
				$order[] = 'apply';
				return new AppliedExtraction(1, []);
			});
		$this->messageMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (ProcessedMessage $m): bool => $m->getStatus() === ProcessedMessage::STATUS_PROCESSED));
		$this->taskMapMapper->expects($this->never())->method('update');

		$this->handler->handleSuccess(42, ['output' => self::RESPONSE]);

		$this->assertSame(['settle', 'apply'], $order);
	}

	public function testATaskAnotherCallerSettledFirstWritesNothing(): void {
		// Both loaded it while it was pending; the other one's claim landed first.
		$this->taskMapMapper->method('findByTaskId')->willReturn($this->pendingMap());
		$this->taskMapMapper->method('settle')->willReturn(false);
		$this->bookingService->expects($this->never())->method('applyExtraction');
		$this->messageMapper->expects($this->never())->method('update');

		$this->handler->handleSuccess(42, ['output' => self::RESPONSE]);
	}

	public function testATaskAlreadySettledIsNotClaimedAgain(): void {
		$map = $this->pendingMap();
		$map->setStatus(TaskMap::STATUS_COMPLETED);
		$this->taskMapMapper->method('findByTaskId')->willReturn($map);
		$this->taskMapMapper->expects($this->never())->method('settle');
		$this->bookingService->expects($this->never())->method('applyExtraction');

		$this->handler->handleSuccess(42, ['output' => self::RESPONSE]);
	}

	public function testAFailureIsRecordedOnlyByTheCallerThatSettlesIt(): void {
		$this->taskMapMapper->method('findByTaskId')->willReturn($this->pendingMap());
		$this->taskMapMapper->expects($this->once())
			->method('settle')
			->with(5, TaskMap::STATUS_FAILED)
			->willReturn(false);
		$this->messageMapper->expects($this->never())->method('update');

		$this->handler->handleFailure($this->failedTask(), 'provider error');
	}

	public function testAFailureMarksTheMessageFailed(): void {
		$this->taskMapMapper->method('findByTaskId')->willReturn($this->pendingMap());
		$this->taskMapMapper->method('settle')->willReturn(true);
		$this->messageMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (ProcessedMessage $m): bool => $m->getStatus() === ProcessedMessage::STATUS_FAILED
				&& $m->getFailureKind() === ProcessedMessage::FAILURE_PROVIDER));

		$this->handler->handleFailure($this->failedTask(), 'provider error');
	}

	public function testALostTaskIsRecordedOnlyByTheCallerThatSettlesIt(): void {
		$this->taskMapMapper->expects($this->once())
			->method('settle')
			->with(5, TaskMap::STATUS_FAILED)
			->willReturn(false);
		$this->messageMapper->expects($this->never())->method('update');

		$this->handler->handleLost($this->pendingMap(), 'gone');
	}
}
