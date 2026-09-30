<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\ExtractionQueue;
use OCA\TravelManager\Service\IngestionLogger;
use OCA\TravelManager\Service\IngestionService;
use OCA\TravelManager\Service\UserSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reading the mailbox. Since the extraction queue landed, ingestion only *stores*
 * new mail as queued and hands over to ExtractionQueue — it never talks to the
 * model itself. The scheduling tests moved to ExtractionQueueTest with the code.
 */
final class IngestionServiceTest extends TestCase {
	private ConfigService&MockObject $configService;
	private IImapClient&MockObject $imapClient;
	private ProcessedMessageMapper&MockObject $processedMessageMapper;
	private ExtractionQueue&MockObject $queue;
	private IngestionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
		$this->imapClient = $this->createMock(IImapClient::class);
		$this->processedMessageMapper = $this->createMock(ProcessedMessageMapper::class);
		$this->queue = $this->createMock(ExtractionQueue::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime());

		$this->service = new IngestionService(
			$this->configService,
			$this->imapClient,
			$this->processedMessageMapper,
			$this->queue,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IngestionLogger::class),
		);
	}

	private function configureUser(): void {
		$this->configService->method('getUserSettings')->willReturn(new UserSettings(
			true, 'imap.example.com', 993, 'ssl', 'travel@example.com', 'INBOX', 15, true,
		));
		$this->configService->method('getImapPassword')->willReturn('secret');
		$this->configService->method('getFetchPerRun')->willReturn(20);
	}

	private function message(string $messageId): ImapMessage {
		return new ImapMessage($messageId, 1, 1, 'Subject', new \DateTimeImmutable(), 'body');
	}

	private function storedMessage(string $status): ProcessedMessage {
		$record = new ProcessedMessage();
		$record->setUserId('alice');
		$record->setMessageId('<x@example.com>');
		$record->setBodyText('body');
		$record->setStatus($status);
		$record->setAttempts(1);
		return $record;
	}

	public function testSkipsUnconfiguredUser(): void {
		$this->configService->method('getUserSettings')->willReturn(new UserSettings(
			false, '', 993, 'ssl', '', 'INBOX', 15, false,
		));
		$this->imapClient->expects($this->never())->method('fetchRecent');
		$this->queue->expects($this->never())->method('pump');

		$this->assertSame(0, $this->service->ingestForUser('alice'));
	}

	public function testReadsAsManyMessagesAsTheFetchSettingSays(): void {
		$this->configureUser();
		$this->imapClient->expects($this->once())
			->method('fetchRecent')
			->with($this->anything(), 20)
			->willReturn([]);

		$this->service->ingestForUser('alice');
	}

	public function testNewMessagesAreQueuedNotSent(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			$this->message('<already@example.com>'),
			$this->message('<fresh@example.com>'),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturnMap([
			['alice', '<already@example.com>', true],
			['alice', '<fresh@example.com>', false],
		]);

		// Only the new message is stored — as queued, with nothing attempted yet.
		// Sending it is the queue's decision, not the mailbox read's.
		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getMessageId() === '<fresh@example.com>'
				&& $m->getStatus() === ProcessedMessage::STATUS_QUEUED
				&& $m->getAttempts() === 0
				&& $m->getBodyText() === 'body'));
		$this->queue->expects($this->once())->method('pump');

		$this->assertSame(1, $this->service->ingestForUser('alice'));
	}

	public function testMessagesAreQueuedInTheOrderTheMailboxReturnedThem(): void {
		// IImapClient hands messages over oldest-first, and ingestion must not
		// re-order them: the queue releases by row id, and booking deduplication
		// treats the first email about a booking as the one that creates it.
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			$this->message('<older@example.com>'),
			$this->message('<newer@example.com>'),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);

		$inserted = [];
		$this->processedMessageMapper->method('insert')
			->willReturnCallback(static function (ProcessedMessage $m) use (&$inserted): ProcessedMessage {
				$inserted[] = $m->getMessageId();
				return $m;
			});

		$this->assertSame(2, $this->service->ingestForUser('alice'));
		$this->assertSame(['<older@example.com>', '<newer@example.com>'], $inserted);
	}

	public function testRetryQueuesTheMessageAndGivesTheQueueAChance(): void {
		$record = $this->storedMessage(ProcessedMessage::STATUS_FAILED);
		$record->setDiscarded(true);
		$this->processedMessageMapper->method('find')->willReturn($record);

		$this->processedMessageMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getStatus() === ProcessedMessage::STATUS_QUEUED
				&& $m->getError() === null
				&& $m->getDiscarded() === false));
		$this->queue->expects($this->once())->method('pump');

		$this->service->retryMessage('alice', 7);
	}

	/**
	 * @return list<array{string}>
	 */
	public static function waitingStatuses(): array {
		return [[ProcessedMessage::STATUS_QUEUED], [ProcessedMessage::STATUS_PROCESSING]];
	}

	#[DataProvider('waitingStatuses')]
	public function testRetryRefusesAMessageThatIsAlreadyWaiting(string $status): void {
		// A second attempt while the first is out would put two tasks in flight
		// for one email, and whichever answered last would win.
		$this->processedMessageMapper->method('find')->willReturn($this->storedMessage($status));
		$this->processedMessageMapper->expects($this->never())->method('update');
		$this->queue->expects($this->never())->method('pump');

		$this->expectException(\RuntimeException::class);
		$this->service->retryMessage('alice', 7);
	}
}
