<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Exception\InvalidBackupException;
use OCA\TravelManager\Service\BackupFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate in front of "replace existing data": anything this lets through may
 * have cost the user their data by the time it fails. Pure — runs under the
 * standalone bootstrap.
 */
final class BackupFormatTest extends TestCase {
	private BackupFormat $format;

	protected function setUp(): void {
		parent::setUp();
		$this->format = new BackupFormat();
	}

	/** @return array<string, mixed> */
	private function backup(): array {
		return [
			'format' => 'travelmanager-backup',
			'formatVersion' => 1,
			'appVersion' => '1.18.0',
			'exportedAt' => '2026-11-05T10:00:00',
			'includesEmailText' => true,
			'trips' => [[
				'id' => 7,
				'name' => 'Paris',
				'type' => 'work',
				'color' => '#AABBCC',
				'startDate' => null,
				'endDate' => null,
				'notes' => null,
				'createdAt' => '2026-01-01T09:00:00',
				'updatedAt' => '2026-01-02T09:00:00',
			]],
			'bookings' => [[
				'id' => 42,
				'tripId' => 7,
				'type' => 'flight',
				'provider' => 'KLM',
				'bookingReference' => 'YGUE6T',
				'confirmationNumber' => null,
				'title' => 'AMS → CDG',
				'status' => 'active',
				'reviewState' => 'confirmed',
				'sourceMessageId' => '<a@x>',
				'duplicateGroupId' => null,
				'confidence' => 1,
				'details' => ['segments' => [['departureLocal' => '2026-07-25T08:35:00']]],
				'startDate' => '2026-07-25T08:35:00',
				'endDate' => null,
				'createdAt' => null,
				'updatedAt' => null,
				'confirmedAt' => null,
			]],
			'messages' => [[
				'mailbox' => 'INBOX',
				'messageId' => '<a@x>',
				'uidValidity' => 1,
				'imapUid' => 2,
				'status' => 'processed',
				'subject' => 'Your flight',
				'sender' => 'KLM',
				'sentAt' => '2026-01-01T09:00:00',
				'bodyText' => 'Hello',
				'attempts' => 1,
				'failureKind' => null,
				'issueReasons' => ['repaired_json'],
				'relatedBookingIds' => [42],
				'error' => null,
				'lastResponse' => '{}',
				'processedAt' => null,
				'discarded' => false,
			]],
		];
	}

	/**
	 * @param array<string, mixed> $backup
	 */
	private function encode(array $backup): string {
		return json_encode($backup, JSON_THROW_ON_ERROR);
	}

	public function testReadsAWellFormedBackup(): void {
		$parsed = $this->format->parse($this->encode($this->backup()));

		$this->assertSame('1.18.0', $parsed->appVersion);
		$this->assertTrue($parsed->includesEmailText);
		$this->assertSame('Paris', $parsed->trips[0]->name);
		$this->assertSame(7, $parsed->bookings[0]->tripId);
		$this->assertSame(1.0, $parsed->bookings[0]->confidence);
		$this->assertSame(['segments' => [['departureLocal' => '2026-07-25T08:35:00']]], $parsed->bookings[0]->details);
		$this->assertSame(['repaired_json'], $parsed->messages[0]->issueReasons);
		$this->assertSame([42], $parsed->messages[0]->relatedBookingIds);
	}

	public function testAnEmptyBackupIsValid(): void {
		$backup = $this->backup();
		$backup['trips'] = $backup['bookings'] = $backup['messages'] = [];

		$parsed = $this->format->parse($this->encode($backup));

		$this->assertSame([], $parsed->bookings);
	}

	public function testBookingsComeBackOldestFirstWhateverTheFileOrder(): void {
		$backup = $this->backup();
		$older = $backup['bookings'][0];
		$older['id'] = 3;
		$backup['bookings'][] = $older;

		$parsed = $this->format->parse($this->encode($backup));

		$this->assertSame([3, 42], array_map(static fn ($b): int => $b->id, $parsed->bookings));
	}

	public function testUnknownFieldsAreIgnored(): void {
		$backup = $this->backup();
		$backup['somethingNew'] = true;
		$backup['bookings'][0]['somethingNew'] = 'x';

		$this->assertCount(1, $this->format->parse($this->encode($backup))->bookings);
	}

	public function testTheFileIsNotAskedForAUser(): void {
		$backup = $this->backup();
		$backup['bookings'][0]['userId'] = 'mallory';

		$parsed = $this->format->parse($this->encode($backup));

		$this->assertFalse(property_exists($parsed->bookings[0], 'userId'));
	}

	public function testRefusesSomethingThatIsNotJson(): void {
		$this->expectException(InvalidBackupException::class);
		$this->expectExceptionMessage('not valid JSON');
		$this->format->parse('{"format":');
	}

	public function testRefusesJsonThatIsNotOurs(): void {
		$this->expectException(InvalidBackupException::class);
		$this->expectExceptionMessage('not a Travel Manager backup');
		$this->format->parse('{"some":"other file"}');
	}

	public function testRefusesAFileFromANewerFormat(): void {
		$backup = $this->backup();
		$backup['formatVersion'] = 2;

		$this->expectException(InvalidBackupException::class);
		$this->expectExceptionMessage('newer version');
		$this->format->parse($this->encode($backup));
	}

	/**
	 * Each breaks one thing, and the message must say where.
	 *
	 * @return array<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}>
	 */
	public static function brokenBackups(): array {
		return [
			'no version' => [static function (array $b) {
				unset($b['formatVersion']);
				return $b;
			}, 'format version'],
			'version as text' => [static function (array $b) {
				$b['formatVersion'] = '1';
				return $b;
			}, 'format version'],
			'missing section' => [static function (array $b) {
				unset($b['messages']);
				return $b;
			}, 'messages: expected a list'],
			'section is a map' => [static function (array $b) {
				$b['trips'] = ['a' => []];
				return $b;
			}, 'trips: expected a list'],
			'row is not an object' => [static function (array $b) {
				$b['trips'] = ['x'];
				return $b;
			}, 'trips[0]: expected an object'],
			'no export date' => [static function (array $b) {
				unset($b['exportedAt']);
				return $b;
			}, 'exportedAt: missing'],
			'blank trip name' => [static function (array $b) {
				$b['trips'][0]['name'] = '  ';
				return $b;
			}, 'trips[0].name'],
			'trip name too long' => [static function (array $b) {
				$b['trips'][0]['name'] = str_repeat('x', 256);
				return $b;
			}, 'trips[0].name: longer than 255'],
			'colour is not a colour' => [static function (array $b) {
				$b['trips'][0]['color'] = 'red;}';
				return $b;
			}, 'trips[0].color'],
			'unknown trip type' => [static function (array $b) {
				$b['trips'][0]['type'] = 'spying';
				return $b;
			}, 'trips[0].type'],
			'repeated trip id' => [static function (array $b) {
				$b['trips'][] = $b['trips'][0];
				return $b;
			}, 'trips[1].id'],
			'unknown booking type' => [static function (array $b) {
				$b['bookings'][0]['type'] = 'ferry';
				return $b;
			}, 'bookings[0].type'],
			'unknown booking status' => [static function (array $b) {
				$b['bookings'][0]['status'] = 'draft';
				return $b;
			}, 'bookings[0].status'],
			'unknown review state' => [static function (array $b) {
				$b['bookings'][0]['reviewState'] = 'active';
				return $b;
			}, 'bookings[0].reviewState'],
			'dangling trip link' => [static function (array $b) {
				$b['bookings'][0]['tripId'] = 999;
				return $b;
			}, 'bookings[0].tripId: no such trip'],
			'details is text' => [static function (array $b) {
				$b['bookings'][0]['details'] = '{}';
				return $b;
			}, 'bookings[0].details'],
			'confidence is text' => [static function (array $b) {
				$b['bookings'][0]['confidence'] = 'high';
				return $b;
			}, 'bookings[0].confidence'],
			'date with an offset' => [static function (array $b) {
				$b['bookings'][0]['startDate'] = '2026-07-25T08:35:00+02:00';
				return $b;
			}, 'bookings[0].startDate'],
			'date that does not exist' => [static function (array $b) {
				$b['bookings'][0]['startDate'] = '2026-02-31T08:35:00';
				return $b;
			}, 'bookings[0].startDate'],
			'repeated booking id' => [static function (array $b) {
				$b['bookings'][] = $b['bookings'][0];
				return $b;
			}, 'bookings[1].id'],
			'no message id' => [static function (array $b) {
				$b['messages'][0]['messageId'] = '';
				return $b;
			}, 'messages[0].messageId'],
			'repeated message id' => [static function (array $b) {
				$b['messages'][] = $b['messages'][0];
				return $b;
			}, 'messages[1].messageId'],
			'unknown message status' => [static function (array $b) {
				$b['messages'][0]['status'] = 'lost';
				return $b;
			}, 'messages[0].status'],
			'unknown failure kind' => [static function (array $b) {
				$b['messages'][0]['failureKind'] = 'bad luck';
				return $b;
			}, 'messages[0].failureKind'],
			'negative attempts' => [static function (array $b) {
				$b['messages'][0]['attempts'] = -1;
				return $b;
			}, 'messages[0].attempts'],
			'discarded is not a flag' => [static function (array $b) {
				$b['messages'][0]['discarded'] = 1;
				return $b;
			}, 'messages[0].discarded'],
			'related ids are text' => [static function (array $b) {
				$b['messages'][0]['relatedBookingIds'] = ['42'];
				return $b;
			}, 'messages[0].relatedBookingIds'],
		];
	}

	/** @param \Closure(array<string, mixed>): array<string, mixed> $break */
	#[DataProvider('brokenBackups')]
	public function testRefusesAndSaysWhere(\Closure $break, string $expected): void {
		$this->expectException(InvalidBackupException::class);
		$this->expectExceptionMessage($expected);
		$this->format->parse($this->encode($break($this->backup())));
	}
}
