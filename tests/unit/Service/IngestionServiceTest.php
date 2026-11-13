<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Service\AttachmentService;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\Dto\AttachmentRecord;
use OCA\TravelManager\Service\Dto\AttachmentText;
use OCA\TravelManager\Service\ExtractionQueue;
use OCA\TravelManager\Service\IngestionLogger;
use OCA\TravelManager\Service\IngestionService;
use OCA\TravelManager\Service\UserSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
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
	private AttachmentService&MockObject $attachments;
	/** What the attachment service returns; a test changes it before ingesting. */
	private AttachmentText $attachmentText;
	private IngestionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
		$this->imapClient = $this->createMock(IImapClient::class);
		$this->processedMessageMapper = $this->createMock(ProcessedMessageMapper::class);
		$this->queue = $this->createMock(ExtractionQueue::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime());
		$this->attachments = $this->createMock(AttachmentService::class);
		$this->attachmentText = new AttachmentText();
		$this->attachments->method('read')->willReturnCallback(fn (): AttachmentText => $this->attachmentText);

		$this->service = new IngestionService(
			$this->configService,
			$this->imapClient,
			$this->processedMessageMapper,
			$this->queue,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IngestionLogger::class),
			$this->attachments,
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

	public function testAnEmailWithNoReadableTextIsNeverSentToTheModel(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			new ImapMessage('<empty@example.com>', 1, 1, 'Fwd: Booking', new \DateTimeImmutable(), '', null, "0 multipart/mixed\n1 text/plain, 2 bytes"),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);

		// Recorded as our failure rather than queued: the model would answer an
		// empty prompt with a confident "no booking" and hide the fault.
		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getStatus() === ProcessedMessage::STATUS_FAILED
				&& $m->getFailureKind() === ProcessedMessage::FAILURE_NO_TEXT
				&& str_contains((string)$m->getError(), '1 text/plain, 2 bytes')
				&& $m->getAttempts() === 0));

		$this->assertSame(0, $this->service->ingestForUser('alice'));
	}

	public function testAttachmentsAreReadOnlyForNewMail(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([$this->message('<already@example.com>')]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(true);

		// Downloading PDFs of mail already processed would fetch the same files on
		// every run.
		$this->attachments->expects($this->never())->method('read');

		$this->service->ingestForUser('alice');
	}

	/** "Your ticket is attached": the body says nothing, the PDF says everything. */
	public function testABlankBodyWithAReadablePdfIsSentToTheModel(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			new ImapMessage('<ticket@example.com>', 1, 1, 'Your ticket', new \DateTimeImmutable(), ''),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);
		$section = "--- ATTACHMENT: ticket.pdf (PDF, 1 page) ---\nMH1204 06:40\t07:45";
		$this->attachmentText = new AttachmentText(
			[$section],
			[new AttachmentRecord('ticket.pdf', AttachmentRecord::OUTCOME_READ, 1, 1, 22)],
		);

		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getStatus() === ProcessedMessage::STATUS_QUEUED
				&& $m->getBodyText() === $section
				&& str_contains((string)$m->getAttachments(), '"outcome":"read"')));

		$this->assertSame(1, $this->service->ingestForUser('alice'));
	}

	public function testABlankBodyWithOnlyAnUnreadablePdfIsStillNoText(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			new ImapMessage('<scan@example.com>', 1, 1, 'Your ticket', new \DateTimeImmutable(), ''),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);
		$this->attachmentText = new AttachmentText(
			[],
			[new AttachmentRecord('scan.pdf', AttachmentRecord::OUTCOME_NO_TEXT, 1, 1, 0)],
		);

		// The error says why, file by file, and the column keeps the record.
		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getFailureKind() === ProcessedMessage::FAILURE_NO_TEXT
				&& str_contains((string)$m->getError(), 'scan.pdf: no_text')
				&& str_contains((string)$m->getAttachments(), '"outcome":"no_text"')));

		$this->assertSame(0, $this->service->ingestForUser('alice'));
	}

	public function testAnEmailWithoutPdfsStoresNoAttachmentRecord(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([$this->message('<plain@example.com>')]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);

		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => $m->getAttachments() === null));

		$this->service->ingestForUser('alice');
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

	public function testOneMessageThatCannotBeStoredDoesNotStopTheRest(): void {
		// It is not written, so it is tried again next run; everything after it
		// in the window is stored now, and the queue still gets its turn.
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			$this->message('<broken@example.com>'),
			$this->message('<fine@example.com>'),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);
		$inserted = [];
		$this->processedMessageMapper->method('insert')
			->willReturnCallback(static function (ProcessedMessage $m) use (&$inserted): ProcessedMessage {
				if ($m->getMessageId() === '<broken@example.com>') {
					throw new \RuntimeException('Data too long for column');
				}
				$inserted[] = $m->getMessageId();
				return $m;
			});
		$this->queue->expects($this->once())->method('pump');

		$this->assertSame(1, $this->service->ingestForUser('alice'));
		$this->assertSame(['<fine@example.com>'], $inserted);
	}

	public function testAMessageAnotherReadStoredFirstIsSkippedQuietly(): void {
		// Cron and "Read mailbox now" can both pass the dedup check; the second
		// insert then hits the unique key, which means the message *is* stored.
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([$this->message('<raced@example.com>')]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);
		$duplicate = $this->createMock(DbException::class);
		$duplicate->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->processedMessageMapper->method('insert')->willThrowException($duplicate);
		$this->queue->expects($this->once())->method('pump');

		$this->assertSame(0, $this->service->ingestForUser('alice'));
	}

	public function testThePdfBreadcrumbIsClearedOnceTheMessageIsStoredOrHasFailed(): void {
		$this->configureUser();
		$this->imapClient->method('fetchRecent')->willReturn([
			$this->message('<stored@example.com>'),
			$this->message('<failed@example.com>'),
		]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);
		$this->processedMessageMapper->method('insert')
			->willReturnCallback(static function (ProcessedMessage $m): ProcessedMessage {
				if ($m->getMessageId() === '<failed@example.com>') {
					throw new \RuntimeException('connection lost');
				}
				return $m;
			});
		// A failure PHP survived is not the crash the breadcrumb guards against.
		$this->attachments->expects($this->exactly(2))->method('finish')->with('alice');

		$this->service->ingestForUser('alice');
	}

	public function testEmailTextIsFittedToItsColumns(): void {
		$this->configureUser();
		$message = new ImapMessage(
			'<long@example.com>', 1, 1, str_repeat('Itinerary ', 40), new \DateTimeImmutable(), "Body\0 text",
		);
		$this->imapClient->method('fetchRecent')->willReturn([$message]);
		$this->processedMessageMapper->method('isProcessed')->willReturn(false);

		$this->processedMessageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (ProcessedMessage $m) => mb_strlen((string)$m->getSubject()) === 255
				&& $m->getBodyText() === 'Body text'));

		$this->service->ingestForUser('alice');
	}

	public function testRetryQueuesTheMessageAndGivesTheQueueAChance(): void {
		$record = $this->storedMessage(ProcessedMessage::STATUS_FAILED);
		$record->setDiscarded(true);
		$this->processedMessageMapper->method('find')->willReturn($record);

		// One conditional statement, from the status this request read; no
		// second write a pump could be overwritten by.
		$this->processedMessageMapper->expects($this->once())
			->method('requeueForRetry')
			->with(7, 'alice', ProcessedMessage::STATUS_FAILED, $this->isInstanceOf(\DateTime::class))
			->willReturn(true);
		$this->processedMessageMapper->expects($this->never())->method('update');
		$this->queue->expects($this->once())->method('pump');

		$this->service->retryMessage('alice', 7);
	}

	public function testASecondRetryThatLostTheRaceIsRefused(): void {
		// Both requests read `failed`; the other one's requeue landed first.
		$this->processedMessageMapper->method('find')->willReturn($this->storedMessage(ProcessedMessage::STATUS_FAILED));
		$this->processedMessageMapper->method('requeueForRetry')->willReturn(false);
		$this->queue->expects($this->never())->method('pump');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('already waiting');
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
		$this->processedMessageMapper->expects($this->never())->method('requeueForRetry');
		$this->queue->expects($this->never())->method('pump');

		$this->expectException(\RuntimeException::class);
		$this->service->retryMessage('alice', 7);
	}
}
