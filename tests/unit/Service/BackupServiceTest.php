<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\Booking;
use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Db\Trip;
use OCA\TravelManager\Db\TripMapper;
use OCA\TravelManager\Exception\InvalidBackupException;
use OCA\TravelManager\Service\BackupFormat;
use OCA\TravelManager\Service\BackupImportPlanner;
use OCA\TravelManager\Service\BackupService;
use OCA\TravelManager\Service\IngestionLogger;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The I/O around the file: what an export holds, the order replace does things
 * in, and how ids are rewritten on the way in.
 */
final class BackupServiceTest extends TestCase {
	private BookingMapper&MockObject $bookings;
	private TripMapper&MockObject $trips;
	private ProcessedMessageMapper&MockObject $messages;
	private TaskMapMapper&MockObject $tasks;
	private IDBConnection&MockObject $db;
	private BackupService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->bookings = $this->createMock(BookingMapper::class);
		$this->trips = $this->createMock(TripMapper::class);
		$this->messages = $this->createMock(ProcessedMessageMapper::class);
		$this->tasks = $this->createMock(TaskMapMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.18.0');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-05T10:00:00'));

		$this->service = new BackupService(
			$this->bookings,
			$this->trips,
			$this->messages,
			$this->tasks,
			new BackupFormat(),
			new BackupImportPlanner(),
			$this->createMock(IngestionLogger::class),
			$apps,
			$time,
			$this->db,
		);
	}

	private function storedMessage(): ProcessedMessage {
		$message = new ProcessedMessage();
		$message->setId(3);
		$message->setUserId('alice');
		$message->setMailbox('INBOX');
		$message->setMessageId('<a@x>');
		$message->setStatus(ProcessedMessage::STATUS_RELATED);
		$message->setBodyText('Dear Alice');
		$message->setLastResponse('{"bookings":[]}');
		$message->setIssueReasons('repaired_json');
		$message->setRelatedBookingIds('42');
		$message->setSentAt(new \DateTime('2026-01-01T09:00:00'));
		return $message;
	}

	private function storedBooking(): Booking {
		$booking = new Booking();
		$booking->setId(42);
		$booking->setUserId('alice');
		$booking->setType('flight');
		$booking->setSourceMessageId('<a@x>');
		$booking->setDetails('{"segments":[{"departureLocal":"2026-07-25T08:35:00"}]}');
		$booking->setStartDate(new \DateTime('2026-07-25T08:35:00'));
		$booking->setTripId(7);
		return $booking;
	}

	private function storedTrip(): Trip {
		$trip = new Trip();
		$trip->setId(7);
		$trip->setUserId('alice');
		$trip->setName('Paris');
		return $trip;
	}

	private function storeEverything(): void {
		$this->trips->method('findAllOldestFirst')->willReturn([$this->storedTrip()]);
		$this->bookings->method('findAllOldestFirst')->willReturn([$this->storedBooking()]);
		$this->messages->method('findAllOldestFirst')->willReturn([$this->storedMessage()]);
	}

	/** Hand out ids as an insert would. */
	private function assignIdsOnInsert(): void {
		$next = 1000;
		$assign = static function ($entity) use (&$next) {
			$entity->setId($next++);
			return $entity;
		};
		$this->trips->method('insert')->willReturnCallback($assign);
		$this->bookings->method('insert')->willReturnCallback($assign);
		$this->messages->method('insert')->willReturnCallback($assign);
	}

	public function testAnExportIsAFileWeWouldAccept(): void {
		$this->storeEverything();

		$parsed = (new BackupFormat())->parse($this->service->export('alice', true));

		$this->assertSame('2026-11-05T10:00:00', $parsed->exportedAt);
		$this->assertSame('<a@x>', $parsed->messages[0]->messageId);
		$this->assertSame('Dear Alice', $parsed->messages[0]->bodyText);
		$this->assertSame(['repaired_json'], $parsed->messages[0]->issueReasons);
		$this->assertSame([42], $parsed->messages[0]->relatedBookingIds);
		$this->assertSame('2026-07-25T08:35:00', $parsed->bookings[0]->startDate);
		$this->assertSame(7, $parsed->bookings[0]->tripId);
	}

	/**
	 * A column added to an entity and forgotten here would be lost on every
	 * restore, silently. `userId` is left out on purpose and `id` is only kept
	 * where something in the file refers to it.
	 */
	public function testAnExportCoversEveryColumn(): void {
		$this->storeEverything();
		$file = json_decode($this->service->export('alice', true), true, 512, JSON_THROW_ON_ERROR);

		foreach ([[Trip::class, 'trips'], [Booking::class, 'bookings'], [ProcessedMessage::class, 'messages']] as [$entity, $section]) {
			foreach ((new \ReflectionClass($entity))->getProperties(\ReflectionProperty::IS_PROTECTED) as $property) {
				if ($property->getDeclaringClass()->getName() === $entity && $property->getName() !== 'userId') {
					$this->assertArrayHasKey($property->getName(), $file[$section][0], $section);
				}
			}
		}
	}

	public function testAnExportNamesNoUser(): void {
		$this->storeEverything();

		$this->assertStringNotContainsString('alice', $this->service->export('alice', true));
	}

	public function testOptingOutLeavesTheEmailTextBehindButKeepsTheDedupRecord(): void {
		$this->storeEverything();

		$json = $this->service->export('alice', false);
		$parsed = (new BackupFormat())->parse($json);

		$this->assertStringNotContainsString('Dear Alice', $json);
		$this->assertFalse($parsed->includesEmailText);
		$this->assertNull($parsed->messages[0]->bodyText);
		$this->assertNull($parsed->messages[0]->lastResponse);
		$this->assertSame('<a@x>', $parsed->messages[0]->messageId);
	}

	public function testABadFileNeverReachesTheDatabase(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$this->bookings->expects($this->never())->method('deleteAllForUser');
		$this->trips->expects($this->never())->method('deleteAllForUser');
		$this->messages->expects($this->never())->method('deleteAllForUser');

		$this->expectException(InvalidBackupException::class);
		$this->service->import('alice', '{"format":"travelmanager-backup","formatVersion":1,"trips":"nope"}', true);
	}

	public function testReplaceDeletesEverythingItWillRestore(): void {
		$this->storeEverything();
		$json = $this->service->export('alice', true);
		$this->assignIdsOnInsert();

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->bookings->expects($this->once())->method('deleteAllForUser')->with('alice');
		$this->trips->expects($this->once())->method('deleteAllForUser')->with('alice');
		$this->messages->expects($this->once())->method('deleteAllForUser')->with('alice');
		$this->tasks->expects($this->once())->method('deleteAllForUser')->with('alice');

		$this->service->import('alice', $json, true);
	}

	public function testAFailedInsertRollsTheDeletionBack(): void {
		$this->storeEverything();
		$json = $this->service->export('alice', true);
		// The mocks still list the deleted rows, so only the message reaches an insert.
		$this->messages->method('insert')->willThrowException(new \RuntimeException('disk full'));

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->service->import('alice', $json, true);
	}

	public function testWithoutReplaceNothingIsDeleted(): void {
		$json = $this->exportedThenEmptied();
		$this->assignIdsOnInsert();

		$this->bookings->expects($this->never())->method('deleteAllForUser');
		$this->trips->expects($this->never())->method('deleteAllForUser');
		$this->messages->expects($this->never())->method('deleteAllForUser');
		$this->tasks->expects($this->never())->method('deleteAllForUser');

		$this->service->import('bob', $json, false);
	}

	public function testRowsLandUnderTheImportingUserWithTheirLinksRewritten(): void {
		$json = $this->exportedThenEmptied();

		$bookings = [];
		$this->trips->method('insert')->willReturnCallback(static function (Trip $trip) {
			$trip->setId(500);
			return $trip;
		});
		$this->bookings->method('insert')->willReturnCallback(static function (Booking $booking) use (&$bookings) {
			$booking->setId(600);
			$bookings[] = $booking;
			return $booking;
		});
		$messages = [];
		$this->messages->method('insert')->willReturnCallback(static function (ProcessedMessage $message) use (&$messages) {
			$message->setId(700);
			$messages[] = $message;
			return $message;
		});

		$report = $this->service->import('bob', $json, false);

		$this->assertSame('bob', $bookings[0]->getUserId());
		$this->assertSame(500, $bookings[0]->getTripId());
		$this->assertSame('2026-07-25 08:35:00', $bookings[0]->getStartDate()?->format('Y-m-d H:i:s'));
		$this->assertSame('bob', $messages[0]->getUserId());
		$this->assertSame('600', $messages[0]->getRelatedBookingIds());
		$this->assertSame('repaired_json', $messages[0]->getIssueReasons());
		$this->assertSame(['trips' => 1, 'bookings' => 1, 'messages' => 1], $report['imported']);
	}

	public function testAWaitingEmailReturnsToTheQueue(): void {
		$stored = $this->storedMessage();
		$stored->setStatus(ProcessedMessage::STATUS_PROCESSING);
		$this->messages->method('findAllOldestFirst')->willReturnOnConsecutiveCalls([$stored], []);
		$json = $this->service->export('alice', true);

		$messages = [];
		$this->messages->method('insert')->willReturnCallback(static function (ProcessedMessage $message) use (&$messages) {
			$message->setId(700);
			$messages[] = $message;
			return $message;
		});

		$report = $this->service->import('bob', $json, false);

		$this->assertSame(ProcessedMessage::STATUS_QUEUED, $messages[0]->getStatus());
		$this->assertSame(1, $report['requeued']);
	}

	public function testAPreviewReportsWithoutWriting(): void {
		$json = $this->exportedThenEmptied();

		$this->db->expects($this->never())->method('beginTransaction');
		$this->bookings->expects($this->never())->method('insert');
		$this->messages->expects($this->never())->method('insert');

		$report = $this->service->preview('bob', $json, false);

		$this->assertSame(['trips' => 1, 'bookings' => 1, 'messages' => 1], $report['imported']);
		$this->assertSame(['trips' => 0, 'bookings' => 0, 'messages' => 0], $report['existing']);
	}

	public function testAPreviewOfReplaceIgnoresWhatIsThereButCountsIt(): void {
		$this->trips->method('findAllOldestFirst')->willReturn([$this->storedTrip()]);
		$this->bookings->method('findAllOldestFirst')->willReturn([$this->storedBooking()]);
		$this->messages->method('findAllOldestFirst')->willReturn([$this->storedMessage()]);
		$this->messages->method('findIdsByMessageId')->willReturn(['<a@x>' => 3]);
		$json = $this->service->export('alice', true);

		$replacing = $this->service->preview('alice', $json, true);
		$merging = $this->service->preview('alice', $json, false);

		$this->assertSame(['trips' => 1, 'bookings' => 1, 'messages' => 1], $replacing['existing']);
		$this->assertSame(['trips' => 1, 'bookings' => 1, 'messages' => 1], $replacing['imported']);
		$this->assertSame([], $replacing['skipped']);
		$this->assertSame(['trips' => 0, 'bookings' => 0, 'messages' => 0], $merging['imported']);
		$this->assertCount(3, $merging['skipped']);
	}

	/** A file holding one of everything, with the database looking empty after. */
	private function exportedThenEmptied(): string {
		$this->trips->method('findAllOldestFirst')->willReturnOnConsecutiveCalls([$this->storedTrip()], [], [], [], []);
		$this->bookings->method('findAllOldestFirst')->willReturnOnConsecutiveCalls([$this->storedBooking()], [], [], [], []);
		$this->messages->method('findAllOldestFirst')->willReturn([$this->storedMessage()]);
		return $this->service->export('alice', true);
	}
}
