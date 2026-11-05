<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Service\BackupImportPlanner;
use OCA\TravelManager\Service\Dto\BackupBooking;
use OCA\TravelManager\Service\Dto\BackupContents;
use OCA\TravelManager\Service\Dto\BackupMessage;
use OCA\TravelManager\Service\Dto\BackupTrip;
use OCA\TravelManager\Service\Dto\ExistingBooking;
use OCA\TravelManager\Service\Dto\ImportSkip;
use PHPUnit\Framework\TestCase;

/**
 * What a backup writes and what gives way to the rows already there. Pure — runs
 * under the standalone bootstrap.
 */
final class BackupImportPlannerTest extends TestCase {
	private BackupImportPlanner $planner;

	protected function setUp(): void {
		parent::setUp();
		$this->planner = new BackupImportPlanner();
	}

	private function trip(int $id, string $name): BackupTrip {
		return new BackupTrip($id, $name, null, null, null, null, null, null, null);
	}

	private function booking(int $id, string $type = 'flight', ?string $source = '<a@x>', ?int $group = null, ?string $title = null): BackupBooking {
		return new BackupBooking($id, null, $type, null, null, null, $title, 'active', 'draft', $source, $group, null, [], null, null, null, null, null);
	}

	private function message(string $id, string $status = 'processed', ?string $body = 'text', ?string $subject = 'Subject'): BackupMessage {
		return new BackupMessage('INBOX', $id, null, null, $status, $subject, null, null, $body, 0, null, [], [], null, null, null, false);
	}

	/**
	 * @param list<BackupTrip> $trips
	 * @param list<BackupBooking> $bookings
	 * @param list<BackupMessage> $messages
	 */
	private function backup(array $trips = [], array $bookings = [], array $messages = []): BackupContents {
		return new BackupContents('1.18.0', '2026-11-05T10:00:00', true, $trips, $bookings, $messages);
	}

	public function testAnEmptyDatabaseMatchesNothingAndRestoresTheWholeFile(): void {
		$backup = $this->backup(
			[$this->trip(1, 'Paris')],
			[$this->booking(1), $this->booking(2)],
			[$this->message('<a@x>')],
		);

		$plan = $this->planner->plan($backup, [], [], []);

		$this->assertCount(1, $plan->trips);
		$this->assertCount(2, $plan->bookings, 'rows in the file are never compared with each other');
		$this->assertCount(1, $plan->messages);
		$this->assertSame([], $plan->skipped);
	}

	public function testAnEmailAlreadyInTheLedgerIsSkippedAndLinked(): void {
		$plan = $this->planner->plan($this->backup(messages: [$this->message('<a@x>'), $this->message('<b@x>')]), ['<a@x>' => 19], [], []);

		$this->assertSame(['<b@x>'], array_map(static fn ($m): string => $m->messageId, $plan->messages));
		$this->assertCount(1, $plan->skipped);
		$this->assertSame(ImportSkip::REASON_MESSAGE_EXISTS, $plan->skipped[0]->reason);
		$this->assertSame(19, $plan->skipped[0]->existingId);
		$this->assertSame('Subject', $plan->skipped[0]->label);
	}

	public function testAMessageWithNoSubjectIsNamedByItsId(): void {
		$plan = $this->planner->plan($this->backup(messages: [$this->message('<a@x>', subject: null)]), ['<a@x>' => 1], [], []);

		$this->assertSame('<a@x>', $plan->skipped[0]->label);
	}

	public function testABookingFromTheSameEmailIsSkippedAndRemembered(): void {
		$plan = $this->planner->plan(
			$this->backup(bookings: [$this->booking(5, title: 'AMS → CDG')]),
			[],
			[new ExistingBooking(50, 'flight', '<a@x>', null)],
			[],
		);

		$this->assertSame([], $plan->bookings);
		$this->assertSame([5 => 50], $plan->bookingMatches);
		$this->assertSame(ImportSkip::REASON_BOOKING_EXISTS, $plan->skipped[0]->reason);
		$this->assertSame('AMS → CDG', $plan->skipped[0]->label);
		$this->assertSame(50, $plan->skipped[0]->existingId);
	}

	public function testTheSameBookingFromADifferentEmailIsImported(): void {
		$plan = $this->planner->plan(
			$this->backup(bookings: [$this->booking(5, source: '<other@x>')]),
			[],
			[new ExistingBooking(50, 'flight', '<a@x>', null)],
			[],
		);

		$this->assertCount(1, $plan->bookings);
		$this->assertSame([], $plan->skipped);
	}

	public function testAFlightDoesNotStandInForTheHotelInTheSameEmail(): void {
		$plan = $this->planner->plan(
			$this->backup(bookings: [$this->booking(5, 'flight'), $this->booking(6, 'accommodation')]),
			[],
			[new ExistingBooking(50, 'flight', '<a@x>', null)],
			[],
		);

		$this->assertSame([5 => 50], $plan->bookingMatches);
		$this->assertSame([6], array_map(static fn ($b): int => $b->id, $plan->bookings));
	}

	public function testEachExistingBookingAnswersForOneRowOfTheFile(): void {
		$plan = $this->planner->plan(
			$this->backup(bookings: [$this->booking(5), $this->booking(6)]),
			[],
			[new ExistingBooking(50, 'flight', '<a@x>', null)],
			[],
		);

		$this->assertSame([5 => 50], $plan->bookingMatches);
		$this->assertSame([6], array_map(static fn ($b): int => $b->id, $plan->bookings));
	}

	public function testImportingTwoFromOneEmailTwiceChangesNothing(): void {
		$plan = $this->planner->plan(
			$this->backup(bookings: [$this->booking(5), $this->booking(6)]),
			[],
			[new ExistingBooking(50, 'flight', '<a@x>', null), new ExistingBooking(51, 'flight', '<a@x>', null)],
			[],
		);

		$this->assertSame([5 => 50, 6 => 51], $plan->bookingMatches);
		$this->assertSame([], $plan->bookings);
	}

	public function testABookingWithNoSourceEmailMatchesNothing(): void {
		$plan = $this->planner->plan(
			$this->backup(bookings: [$this->booking(5, source: null)]),
			[],
			[new ExistingBooking(50, 'flight', null, null)],
			[],
		);

		$this->assertCount(1, $plan->bookings);
	}

	public function testATripOfTheSameNameIsReusedWhateverTheCase(): void {
		$plan = $this->planner->plan($this->backup([$this->trip(1, ' PARÍS ')]), [], [], [9 => 'parís']);

		$this->assertSame([], $plan->trips);
		$this->assertSame([1 => 9], $plan->tripMatches);
		$this->assertSame(ImportSkip::REASON_TRIP_EXISTS, $plan->skipped[0]->reason);
		$this->assertSame(9, $plan->skipped[0]->existingId);
	}

	public function testTheOldestOfSeveralNamesakesTakesTheTrip(): void {
		$plan = $this->planner->plan($this->backup([$this->trip(1, 'Paris')]), [], [], [3 => 'Paris', 8 => 'Paris']);

		$this->assertSame([1 => 3], $plan->tripMatches);
	}

	public function testTwoNamesakesInTheFileBothRestoreIntoAnEmptyDatabase(): void {
		$plan = $this->planner->plan($this->backup([$this->trip(1, 'Paris'), $this->trip(2, 'Paris')]), [], [], []);

		$this->assertCount(2, $plan->trips);
	}

	public function testAnUnansweredEmailGoesBackInTheQueue(): void {
		$plan = $this->planner->plan($this->backup(messages: [
			$this->message('<a@x>', 'queued'),
			$this->message('<b@x>', 'processing'),
			$this->message('<c@x>', 'processed'),
		]), [], [], []);

		$this->assertCount(3, $plan->messages);
		$this->assertSame(2, $plan->requeued());
	}

	public function testAnUnansweredEmailWithNoTextIsLeftForTheMailboxToBringBack(): void {
		$plan = $this->planner->plan($this->backup(messages: [
			$this->message('<a@x>', 'queued', null),
			$this->message('<b@x>', 'processing', ' '),
		]), [], [], []);

		$this->assertSame([], $plan->messages);
		$this->assertSame(0, $plan->requeued());
		$this->assertSame(ImportSkip::REASON_UNFINISHED_NO_TEXT, $plan->skipped[0]->reason);
		$this->assertNull($plan->skipped[0]->existingId);
	}

	public function testAFinishedEmailWithNoTextIsStillTheDedupRecord(): void {
		$plan = $this->planner->plan($this->backup(messages: [$this->message('<a@x>', 'processed', null)]), [], [], []);

		$this->assertCount(1, $plan->messages);
	}

	public function testAGroupIsNamedAfterItsOldestNewMember(): void {
		$backup = $this->backup(bookings: [$this->booking(1, group: 99), $this->booking(2, group: 99)]);
		$plan = $this->planner->plan($backup, [], [], []);

		$this->assertSame([100 => 100, 101 => 100], $this->planner->duplicateGroups($backup, $plan, [1 => 100, 2 => 101], []));
	}

	public function testAGroupOfOneInTheFileClaimsNothing(): void {
		$backup = $this->backup(bookings: [$this->booking(1, group: 99)]);
		$plan = $this->planner->plan($backup, [], [], []);

		$this->assertSame([], $this->planner->duplicateGroups($backup, $plan, [1 => 100], []));
	}

	public function testASkippedMemberBringsItsCounterpartIntoTheGroup(): void {
		$backup = $this->backup(bookings: [$this->booking(1, source: '<a@x>', group: 99), $this->booking(2, source: '<b@x>', group: 99)]);
		$existing = [new ExistingBooking(50, 'flight', '<a@x>', null)];
		$plan = $this->planner->plan($backup, [], $existing, []);

		$this->assertSame([100 => 50, 50 => 50], $this->planner->duplicateGroups($backup, $plan, [2 => 100], $existing));
	}

	public function testANewMemberJoinsTheGroupItsCounterpartIsAlreadyIn(): void {
		$backup = $this->backup(bookings: [$this->booking(1, source: '<a@x>', group: 99), $this->booking(2, source: '<b@x>', group: 99)]);
		$existing = [new ExistingBooking(50, 'flight', '<a@x>', 30)];
		$plan = $this->planner->plan($backup, [], $existing, []);

		$this->assertSame([100 => 30], $this->planner->duplicateGroups($backup, $plan, [2 => 100], $existing));
	}

	public function testAGroupWhollyAlreadyHereIsLeftAlone(): void {
		$backup = $this->backup(bookings: [$this->booking(1, source: '<a@x>', group: 99), $this->booking(2, source: '<b@x>', group: 99)]);
		$existing = [new ExistingBooking(50, 'flight', '<a@x>', null), new ExistingBooking(51, 'flight', '<b@x>', null)];
		$plan = $this->planner->plan($backup, [], $existing, []);

		$this->assertSame([], $this->planner->duplicateGroups($backup, $plan, [], $existing));
	}
}
