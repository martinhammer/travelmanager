<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\Booking;
use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\TripMapper;
use OCA\TravelManager\Service\BookingMatcher;
use OCA\TravelManager\Service\BookingService;
use OCA\TravelManager\Service\Dto\BookingMatch;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the one-message-one-booking rule: a message creates a booking, and a
 * later message about a booking the user already has is reported instead of
 * applied. Which bookings count as the same one is BookingMatcherTest\'s job —
 * the real matcher is used here rather than a mock so the two cannot drift, and
 * the candidate list is what the mapper mock controls.
 *
 * Needs a Nextcloud server checkout to run (mocks QBMapper/OCP types) — see §7
 * of CLAUDE.md.
 */
final class BookingServiceTest extends TestCase {
	private BookingMapper&MockObject $bookingMapper;
	private BookingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->bookingMapper = $this->createMock(BookingMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$this->service = new BookingService(
			$this->bookingMapper,
			$this->createMock(TripMapper::class),
			new BookingMatcher(),
			$this->createMock(IDBConnection::class),
			$time,
		);
	}

	/**
	 * @param Booking[] $candidates
	 */
	private function candidates(array $candidates): void {
		$this->bookingMapper->method('findMatchCandidates')->willReturn($candidates);
	}

	private function extracted(string $status = 'confirmed'): ExtractedBooking {
		return new ExtractedBooking(
			'flight',
			'KLM',
			'YGUE6T',
			'29276863',
			$status,
			'AMS → SOU',
			['segments' => [['departureLocal' => '2026-07-25T08:35:00']]],
			'2026-07-25T08:35:00',
			'2026-07-25T10:00:00',
			0.9,
		);
	}

	private function existing(): Booking {
		$booking = new Booking();
		$booking->setId(12);
		$booking->setUserId('alice');
		$booking->setType('flight');
		$booking->setProvider('KLM');
		$booking->setBookingReference('YGUE6T');
		$booking->setConfirmationNumber('29276863');
		$booking->setDetails('{"segments":[{"departureLocal":"2026-07-25T08:35:00"}]}');
		$booking->setStartDate(new \DateTime('2026-07-25T08:35:00'));
		$booking->setStatus(Booking::STATUS_ACTIVE);
		$booking->setReviewState(Booking::REVIEW_CONFIRMED);
		return $booking;
	}

	public function testFirstMessageCreatesADraftBooking(): void {
		$this->candidates([]);
		$this->bookingMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (Booking $b): bool => $b->getReviewState() === Booking::REVIEW_DRAFT
				&& $b->getStatus() === Booking::STATUS_ACTIVE
				&& $b->getBookingReference() === 'YGUE6T'
				&& $b->getSourceMessageId() === '<m1@example.com>'));
		$this->bookingMapper->expects($this->never())->method('update');

		$applied = $this->service->applyExtraction('alice', '<m1@example.com>', [$this->extracted()]);

		$this->assertSame(1, $applied->created);
		$this->assertSame([], $applied->related);
	}

	public function testSecondMessageForTheSameBookingIsReportedNotApplied(): void {
		$this->candidates([$this->existing()]);
		// The whole point: an existing booking is never written to.
		$this->bookingMapper->expects($this->never())->method('update');
		$this->bookingMapper->expects($this->never())->method('insert');

		$applied = $this->service->applyExtraction('alice', '<m2@example.com>', [$this->extracted()]);

		$this->assertSame(0, $applied->created);
		$this->assertCount(1, $applied->related);
		$this->assertStringContainsString('#12', $applied->related[0]->description);
		$this->assertStringContainsString('not supported yet', $applied->related[0]->description);
		$this->assertTrue($applied->related[0]->suppressed);
		$this->assertSame(BookingMatch::REASON_IDENTIFIER, $applied->related[0]->reason);
		$this->assertSame(1, $applied->suppressedCount());
		// The id, not only the sentence: this is what lets the Messages view
		// offer to open the booking rather than merely name it.
		$this->assertSame([12], $applied->relatedBookingIds());
	}

	public function testACancellationIsCalledOutByNameInTheReport(): void {
		// Leaving the booking untouched matters most here, so the notice has to
		// say plainly that the user must act.
		$this->candidates([$this->existing()]);

		$applied = $this->service->applyExtraction('alice', '<m3@example.com>', [$this->extracted('cancelled')]);

		$this->assertStringContainsString('CANCELLED', $applied->related[0]->description);
		$this->assertStringContainsString('yourself', $applied->related[0]->description);
	}

	public function testACancellationInAFirstMessageStillCreatesACancelledBooking(): void {
		$this->candidates([]);
		$this->bookingMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (Booking $b): bool => $b->getStatus() === Booking::STATUS_CANCELLED
				&& $b->getReviewState() === Booking::REVIEW_DRAFT));

		$this->assertSame(1, $this->service->applyExtraction('alice', '<m4@example.com>', [$this->extracted('cancelled')])->created);
	}

	public function testMixedResultsAreCountedSeparately(): void {
		$hotel = new ExtractedBooking(
			'accommodation',
			'Booking.com',
			'HOTEL-1',
			null,
			'confirmed',
			'Hotel Sol',
			['checkIn' => ['local' => '2026-07-25T15:00:00']],
			'2026-07-25T15:00:00',
			'2026-07-28T11:00:00',
			null,
		);
		// The flight already exists; the hotel does not.
		$this->bookingMapper->method('findMatchCandidates')->willReturnCallback(
			fn (string $u, string $type): array => $type === 'flight' ? [$this->existing()] : [],
		);
		$this->bookingMapper->expects($this->once())->method('insert');

		$applied = $this->service->applyExtraction('alice', '<m5@example.com>', [$this->extracted(), $hotel]);

		$this->assertSame(1, $applied->created);
		$this->assertCount(1, $applied->related);
	}

	public function testAPossibleDuplicateIsStoredAndFlaggedRatherThanDropped(): void {
		// Same airline, same day, but the two references disagree — evidence
		// both ways. Suppressing would be the one irreversible mistake here.
		$other = $this->existing();
		$other->setBookingReference('ZZZZZZ');
		$other->setConfirmationNumber(null);
		$this->candidates([$other]);
		// The resemblance is recorded on the booking, which is what the pair of
		// them is about — not on the message, which is only where it was noticed.
		$this->bookingMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (Booking $b): bool => $b->getPossibleDuplicateOf() === 12));

		$applied = $this->service->applyExtraction('alice', '<m6@example.com>', [$this->extracted()]);

		$this->assertSame(1, $applied->created);
		$this->assertCount(1, $applied->related);
		$this->assertFalse($applied->related[0]->suppressed);
		$this->assertSame(BookingMatch::REASON_POSSIBLE, $applied->related[0]->reason);
		$this->assertSame(0, $applied->suppressedCount());
		$this->assertStringContainsString('may duplicate', $applied->related[0]->description);
		// messages.related_booking_ids means only "this email is about bookings
		// that already existed". A possible duplicate is not that.
		$this->assertSame([], $applied->relatedBookingIds());
	}

	public function testASuppressedMatchLeavesNoDuplicateFlagBehind(): void {
		// Nothing was written, so there is no second booking to compare against.
		$this->candidates([$this->existing()]);
		$this->bookingMapper->expects($this->never())->method('insert');

		$applied = $this->service->applyExtraction('alice', '<m8@example.com>', [$this->extracted()]);

		$this->assertSame([12], $applied->relatedBookingIds());
	}

	public function testDismissingADuplicateClearsBothDirections(): void {
		// The flag reads the same on both cards, so clearing it on one and not
		// the other would leave a lie on the other.
		$booking = $this->existing();
		$booking->setPossibleDuplicateOf(99);
		$this->bookingMapper->method('find')->willReturn($booking);
		$this->bookingMapper->expects($this->once())
			->method('clearPossibleDuplicatesOf')
			->with('alice', 12);
		$this->bookingMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (Booking $b): bool => $b->getPossibleDuplicateOf() === null));

		$this->service->clearPossibleDuplicate('alice', 12);
	}

	public function testDiscardingUnlinksTheBookingFromItsTrip(): void {
		// Discarding says the booking is wrong; a trip groups travel that is real.
		// Leaving it filed would keep a rejected booking feeding the trip's
		// derived dates and type lozenges.
		$booking = $this->existing();
		$booking->setTripId(3);
		$this->bookingMapper->method('find')->willReturn($booking);
		$this->bookingMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (Booking $b): bool => $b->getTripId() === null
				&& $b->getReviewState() === Booking::REVIEW_DISCARDED))
			->willReturn($booking);

		$this->service->setReviewState('alice', 12, Booking::REVIEW_DISCARDED);
	}

	public function testArchivingKeepsTheTripLink(): void {
		// Archiving says the travel happened and is done with, which is precisely
		// when it belongs to its trip — emptying past trips would destroy the
		// history the Trips view and the calendar are built on.
		$booking = $this->existing();
		$booking->setTripId(3);
		$this->bookingMapper->method('find')->willReturn($booking);
		$this->bookingMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (Booking $b): bool => $b->getTripId() === 3))
			->willReturn($booking);

		$this->service->setReviewState('alice', 12, Booking::REVIEW_ARCHIVED);
	}

	public function testOnlyAConfirmedBookingCanBeLinkedToATrip(): void {
		// A trip groups travel you have decided is real; a draft is an extraction
		// nobody has vouched for yet.
		$draft = $this->existing();
		$draft->setReviewState(Booking::REVIEW_DRAFT);
		$this->bookingMapper->method('find')->willReturn($draft);
		$this->bookingMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->assignBookingToTrip('alice', 12, 3);
	}

	public function testUnlinkingIsAllowedFromAnyState(): void {
		// Otherwise a booking linked while confirmed and later restored to draft
		// would be stranded on the trip with no way out of it.
		$draft = $this->existing();
		$draft->setReviewState(Booking::REVIEW_DRAFT);
		$draft->setTripId(3);
		$this->bookingMapper->method('find')->willReturn($draft);
		$this->bookingMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (Booking $b): bool => $b->getTripId() === null))
			->willReturn($draft);

		$this->service->assignBookingToTrip('alice', 12, null);
	}

	public function testPurgingABookingClearsEdgesPointingAtIt(): void {
		// The column is not a foreign key, so nothing else would tidy up.
		$this->bookingMapper->method('find')->willReturn($this->existing());
		$this->bookingMapper->expects($this->once())
			->method('clearPossibleDuplicatesOf')
			->with('alice', 12);
		$this->bookingMapper->expects($this->once())->method('delete');

		$this->service->purge('alice', 12);
	}

	public function testAConfirmationNumberReusedAsAReferenceIsTheSameBooking(): void {
		// The observed miss, end to end: the second email calls the first
		// email\'s confirmation number its booking reference.
		$this->candidates([$this->existing()]);
		$this->bookingMapper->expects($this->never())->method('insert');

		$second = new ExtractedBooking(
			'flight',
			'KLM',
			'29276863',
			null,
			'confirmed',
			'AMS → SOU',
			['segments' => [['departureLocal' => '2026-07-25T08:35:00']]],
			'2026-07-25T08:35:00',
			null,
			null,
		);

		$applied = $this->service->applyExtraction('alice', '<m7@example.com>', [$second]);

		$this->assertSame(0, $applied->created);
		$this->assertSame([12], $applied->relatedBookingIds());
	}
}
