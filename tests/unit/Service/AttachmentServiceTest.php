<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Exception\ImapException;
use OCA\TravelManager\Exception\PdfException;
use OCA\TravelManager\Imap\AttachmentRef;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapConnection;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Pdf\IPdfReader;
use OCA\TravelManager\Pdf\PdfText;
use OCA\TravelManager\Service\AttachmentService;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\Dto\AttachmentRecord;
use OCA\TravelManager\Service\Dto\AttachmentText;
use OCA\TravelManager\Service\IngestionLogger;
use OCA\TravelManager\Service\MessageText;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase {
	private IImapClient&MockObject $imapClient;
	private IPdfReader&MockObject $reader;
	private ConfigService&MockObject $config;
	private AttachmentService $service;
	/** @var list<?string> every breadcrumb write, in order */
	private array $breadcrumbs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->imapClient = $this->createMock(IImapClient::class);
		$this->reader = $this->createMock(IPdfReader::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('isPdfAttachmentsEnabled')->willReturn(true);
		$this->config->method('setPdfReading')->willReturnCallback(function (string $userId, ?string $messageId): void {
			$this->breadcrumbs[] = $messageId;
		});

		$this->service = new AttachmentService(
			$this->imapClient,
			$this->reader,
			$this->config,
			$this->createMock(IngestionLogger::class),
		);
	}

	private static function connection(): ImapConnection {
		return new ImapConnection('imap.example.com', 993, 'ssl', 'travel@example.com', 'secret', 'INBOX');
	}

	/** @param list<AttachmentRef> $refs */
	private static function message(array $refs): ImapMessage {
		return new ImapMessage('<ticket@example.com>', 7, 3, 'Your ticket', null, '', null, null, $refs);
	}

	private static function ref(string $mimeId, ?string $filename = null, int $bytes = 1000): AttachmentRef {
		return new AttachmentRef($mimeId, $filename ?? 'file' . $mimeId . '.pdf', $bytes);
	}

	/** @param list<AttachmentRef> $refs */
	private function read(array $refs): AttachmentText {
		return $this->service->read('alice', self::connection(), self::message($refs));
	}

	/** @return list<string> */
	private static function outcomes(AttachmentText $text): array {
		return array_map(static fn (AttachmentRecord $r): string => $r->outcome, $text->records);
	}

	public function testAnEmailWithoutPdfsDownloadsNothing(): void {
		$this->imapClient->expects($this->never())->method('fetchParts');

		$text = $this->read([]);

		$this->assertSame([], $text->sections);
		$this->assertSame([], $text->records);
	}

	public function testAReadPdfBecomesASectionAndARecord(): void {
		$this->imapClient->expects($this->once())
			->method('fetchParts')
			->with($this->anything(), 3, 7, ['2'])
			->willReturn(['2' => '%PDF-bytes']);
		$this->reader->method('read')->with('%PDF-bytes', AttachmentService::MAX_PAGES)
			->willReturn(new PdfText(2, 2, "MH1204 06:40\t07:45"));

		$text = $this->read([self::ref('2', 'ticket.pdf')]);

		$this->assertSame(["--- ATTACHMENT: ticket.pdf (PDF, 2 pages) ---\nMH1204 06:40\t07:45"], $text->sections);
		$record = $text->records[0];
		$this->assertSame(AttachmentRecord::OUTCOME_READ, $record->outcome);
		$this->assertSame([2, 2, 18, false], [$record->pages, $record->pagesRead, $record->chars, $record->truncated]);
	}

	public function testTheAdminSwitchLeavesEveryPdfUnreadButRecorded(): void {
		$config = $this->createMock(ConfigService::class);
		$config->method('isPdfAttachmentsEnabled')->willReturn(false);
		$service = new AttachmentService($this->imapClient, $this->reader, $config, $this->createMock(IngestionLogger::class));
		$this->imapClient->expects($this->never())->method('fetchParts');

		$text = $service->read('alice', self::connection(), self::message([self::ref('2'), self::ref('3')]));

		$this->assertSame([AttachmentRecord::OUTCOME_DISABLED, AttachmentRecord::OUTCOME_DISABLED], self::outcomes($text));
	}

	/**
	 * A fatal error while reading is out of reach of any catch, and the message
	 * was never stored, so the next run would fetch it and die again for ever.
	 */
	public function testABreadcrumbLeftByACrashSkipsThatMessagesPdfs(): void {
		$config = $this->createMock(ConfigService::class);
		$config->method('isPdfAttachmentsEnabled')->willReturn(true);
		$config->method('getPdfReading')->with('alice')->willReturn('<ticket@example.com>');
		$cleared = [];
		$config->method('setPdfReading')->willReturnCallback(static function (string $userId, ?string $messageId) use (&$cleared): void {
			$cleared[] = $messageId;
		});
		$service = new AttachmentService($this->imapClient, $this->reader, $config, $this->createMock(IngestionLogger::class));
		$this->imapClient->expects($this->never())->method('fetchParts');
		$this->reader->expects($this->never())->method('read');

		$text = $service->read('alice', self::connection(), self::message([self::ref('2')]));

		$this->assertSame([AttachmentRecord::OUTCOME_CRASHED], self::outcomes($text));
		// Still set until the message is stored: if storing dies too, the next
		// run must skip these files again rather than read them.
		$this->assertSame([], $cleared);
		$service->finish('alice');
		$this->assertSame([null], $cleared);
	}

	public function testABreadcrumbForAnotherMessageIsNotThisOnesConcern(): void {
		$this->config->method('getPdfReading')->willReturn('<other@example.com>');
		$this->imapClient->method('fetchParts')->willReturn(['2' => '%PDF']);
		$this->reader->method('read')->willReturn(new PdfText(1, 1, 'text'));

		$this->assertSame([AttachmentRecord::OUTCOME_READ], self::outcomes($this->read([self::ref('2')])));
	}

	public function testTheBreadcrumbIsSetWhileReadingAndClearedOnlyByFinish(): void {
		$this->imapClient->method('fetchParts')->willReturn(['2' => '%PDF']);
		$this->reader->method('read')->willReturnCallback(function (): PdfText {
			$this->assertSame(['<ticket@example.com>'], $this->breadcrumbs, 'set before reading');
			return new PdfText(1, 1, 'text');
		});

		$this->read([self::ref('2')]);

		// A fatal error between reading and storing the row must still leave it.
		$this->assertSame(['<ticket@example.com>'], $this->breadcrumbs);
		$this->service->finish('alice');
		$this->assertSame(['<ticket@example.com>', null], $this->breadcrumbs);
	}

	public function testAReadThatThrowsIsAFailureAndFinishStillClears(): void {
		$this->imapClient->method('fetchParts')->willReturn(['2' => '%PDF']);
		$this->reader->method('read')->willThrowException(new \Error('deep in a decoder'));

		$text = $this->read([self::ref('2')]);
		$this->service->finish('alice');

		$this->assertSame([AttachmentRecord::OUTCOME_FAILED], self::outcomes($text));
		$this->assertSame(['<ticket@example.com>', null], $this->breadcrumbs);
	}

	public function testFinishWritesNothingWhenNoPdfWasRead(): void {
		$this->read([]);
		$this->service->finish('alice');

		$this->assertSame([], $this->breadcrumbs);
	}

	public function testTheListedSizeIsAlreadyTheDecodedEstimate(): void {
		// Horde scales a base64 part's size by 3/4 before we see it. Scaling it
		// again let this 6 MB file through, to be downloaded and then refused.
		$this->imapClient->expects($this->never())->method('fetchParts');

		$text = $this->read([self::ref('2', null, 6 * 1024 * 1024)]);

		$this->assertSame([AttachmentRecord::OUTCOME_TOO_LARGE], self::outcomes($text));
	}

	public function testOnlyTheFirstPdfsWithinTheCapsAreDownloaded(): void {
		$tooLarge = AttachmentService::MAX_BYTES + 1;
		$refs = [self::ref('2'), self::ref('3', null, $tooLarge), self::ref('4'), self::ref('5'), self::ref('6')];
		$this->imapClient->expects($this->once())
			->method('fetchParts')
			->with($this->anything(), 3, 7, ['2', '4', '5'])
			->willReturn(['2' => '%PDF', '4' => '%PDF', '5' => '%PDF']);
		$this->reader->method('read')->willReturn(new PdfText(1, 1, 'text'));

		$text = $this->read($refs);

		$this->assertSame([
			AttachmentRecord::OUTCOME_READ,
			AttachmentRecord::OUTCOME_TOO_LARGE,
			AttachmentRecord::OUTCOME_READ,
			AttachmentRecord::OUTCOME_READ,
			AttachmentRecord::OUTCOME_TOO_MANY,
		], self::outcomes($text));
		$this->assertCount(3, $text->sections);
	}

	public function testAFileLargerThanItsEncodedSizeSuggestedIsNotRead(): void {
		$this->imapClient->method('fetchParts')->willReturn(['2' => str_repeat('x', AttachmentService::MAX_BYTES + 1)]);
		$this->reader->expects($this->never())->method('read');

		$this->assertSame([AttachmentRecord::OUTCOME_TOO_LARGE], self::outcomes($this->read([self::ref('2')])));
	}

	public function testAFailedDownloadIsRecordedAndNothingIsRead(): void {
		$this->imapClient->method('fetchParts')->willThrowException(new ImapException('connection reset'));
		$this->reader->expects($this->never())->method('read');

		$text = $this->read([self::ref('2'), self::ref('3')]);

		$this->assertSame([AttachmentRecord::OUTCOME_FAILED, AttachmentRecord::OUTCOME_FAILED], self::outcomes($text));
		$this->assertSame([], $this->breadcrumbs, 'nothing to guard when nothing is read');
	}

	public function testAPartMissingFromTheDownloadIsAFailure(): void {
		$this->imapClient->method('fetchParts')->willReturn(['3' => '%PDF']);
		$this->reader->method('read')->willReturn(new PdfText(1, 1, 'text'));

		$text = $this->read([self::ref('2'), self::ref('3')]);

		$this->assertSame([AttachmentRecord::OUTCOME_FAILED, AttachmentRecord::OUTCOME_READ], self::outcomes($text));
	}

	public function testReaderFailuresAreToldApart(): void {
		$this->imapClient->method('fetchParts')->willReturn(['2' => 'a', '3' => 'b', '4' => 'c']);
		$this->reader->method('read')->willReturnCallback(static fn (string $bytes): PdfText => match ($bytes) {
			'a' => throw new PdfException('needs a password', true),
			'b' => throw new PdfException('malformed'),
			default => new PdfText(1, 1, ''),
		});

		$text = $this->read([self::ref('2'), self::ref('3'), self::ref('4')]);

		$this->assertSame([
			AttachmentRecord::OUTCOME_ENCRYPTED,
			AttachmentRecord::OUTCOME_FAILED,
			AttachmentRecord::OUTCOME_NO_TEXT,
		], self::outcomes($text));
		$this->assertSame([], $text->sections);
	}

	public function testLongTextAndUnreadPagesAreMarkedTruncated(): void {
		$this->imapClient->method('fetchParts')->willReturn(['2' => 'a', '3' => 'b']);
		$this->reader->method('read')->willReturnCallback(static fn (string $bytes): PdfText => $bytes === 'a'
			? new PdfText(1, 1, str_repeat('word ', 5000))
			: new PdfText(25, AttachmentService::MAX_PAGES, 'short'));

		$text = $this->read([self::ref('2', 'long.pdf'), self::ref('3', 'terms.pdf')]);

		$this->assertTrue($text->records[0]->truncated);
		$this->assertLessThanOrEqual(MessageText::PER_ATTACHMENT, $text->records[0]->chars);
		$this->assertTrue($text->records[1]->truncated);
		$this->assertStringStartsWith('--- ATTACHMENT: terms.pdf (PDF, first 10 of 25 pages) ---', $text->sections[1]);
	}

	public function testAnUnnamedPdfIsNumberedInItsHeading(): void {
		$this->imapClient->method('fetchParts')->willReturn(['2' => 'a']);
		$this->reader->method('read')->willReturn(new PdfText(1, 1, 'text'));

		$text = $this->service->read('alice', self::connection(), self::message([new AttachmentRef('2', null, 100)]));

		$this->assertStringStartsWith('--- ATTACHMENT: attachment 1 (PDF, 1 page) ---', $text->sections[0]);
		$this->assertNull($text->records[0]->filename);
	}
}
