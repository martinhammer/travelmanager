<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMap;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\ExtractionService;
use OCA\TravelManager\Service\IngestionLogger;
use OCA\TravelManager\Service\IngestionService;
use OCA\TravelManager\Service\UserSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IngestionServiceTest extends TestCase {
	private ConfigService&MockObject $configService;
	private IImapClient&MockObject $imapClient;
	private ILlmService&MockObject $llmService;
	private ProcessedMessageMapper&MockObject $processedMessageMapper;
	private TaskMapMapper&MockObject $taskMapMapper;
	private IngestionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
		$this->imapClient = $this->createMock(IImapClient::class);
		$this->llmService = $this->createMock(ILlmService::class);
		$this->processedMessageMapper = $this->createMock(ProcessedMessageMapper::class);
		$this->taskMapMapper = $this->createMock(TaskMapMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$this->service = new IngestionService(
			$this->configService,
			$this->imapClient,
			$this->llmService,
			new ExtractionService(),
			$this->processedMessageMapper,
			$this->taskMapMapper,
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
		$this->configService->method('getRateLimitPerRun')->willReturn(20);
	}

	private function message(string $messageId): ImapMessage {
		return new ImapMessage($messageId, 1, 1, 'Subject', new \DateTimeImmutable(), 'body');
	}

	public function testSkipsUnconfiguredUser(): void {
		$this->configService->method('getUserSettings')->willReturn(new UserSettings(
			false, '', 993, 'ssl', '', 'INBOX', 15, false,
		));
		$this->imapClient->expects($this->never())->method('fetchRecent');

		$this->assertSame(0, $this->service->ingestForUser('alice'));
	}

	public function testDedupSkipsAlreadyProcessedMessages(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			$this->message('<already@example.com>'),
			$this->message('<fresh@example.com>'),
		]);

		// First message already processed, second is new.
		$this->processedMessageMapper->method('isProcessed')->willReturnMap([
			['alice', '<already@example.com>', true],
			['alice', '<fresh@example.com>', false],
		]);

		// Only the new message is recorded and scheduled.
		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getMessageId() === '<fresh@example.com>'
				&& $m->getStatus() === ProcessedMessage::STATUS_PROCESSING));

		$this->llmService->expects($this->once())
			->method('scheduleText2Text')
			->with($this->isType('string'), 'alice', '<fresh@example.com>')
			->willReturn(42);

		$this->taskMapMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (TaskMap $t) => $t->getTaskId() === 42
				&& $t->getMessageId() === '<fresh@example.com>'
				&& $t->getStatus() === TaskMap::STATUS_PENDING));

		$this->assertSame(1, $this->service->ingestForUser('alice'));
	}

	public function testSchedulingFailureMarksMessageFailed(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([$this->message('<x@example.com>')]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);
		$this->llmService->method('scheduleText2Text')->willThrowException(new \RuntimeException('no provider'));

		// Inserted as processing, then updated to failed; no task map row.
		$this->processedMessageMapper->expects($this->once())->method('insert');
		$this->processedMessageMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getStatus() === ProcessedMessage::STATUS_FAILED));
		$this->taskMapMapper->expects($this->never())->method('insert');

		$this->assertSame(0, $this->service->ingestForUser('alice'));
	}
}
