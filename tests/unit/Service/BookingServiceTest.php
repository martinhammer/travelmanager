<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Db\Booking;
use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\TripMapper;
use OCA\TravelManager\Service\BookingService;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the one-message-one-booking rule: a message creates a booking, and a
 * later message matching the same natural key is reported instead of applied.
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
			$this->createMock(IDBConnection::class),
			$time,
		);
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
		$booking->setStatus(Booking::STATUS_ACTIVE);
		$booking->setReviewState(Booking::REVIEW_CONFIRMED);
		return $booking;
	}

	public function testFirstMessageCreatesADraftBooking(): void {
		$this->bookingMapper->method('findByReference')->willReturn(null);
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
		$this->bookingMapper->method('findByReference')->willReturn($this->existing());
		// The whole point: an existing booking is never written to.
		$this->bookingMapper->expects($this->never())->method('update');
		$this->bookingMapper->expects($this->never())->method('insert');

		$applied = $this->service->applyExtraction('alice', '<m2@example.com>', [$this->extracted()]);

		$this->assertSame(0, $applied->created);
		$this->assertCount(1, $applied->related);
		$this->assertStringContainsString('#12', $applied->related[0]->description);
		$this->assertStringContainsString('not supported yet', $applied->related[0]->description);
		// The id, not only the sentence: this is what lets the Messages view
		// offer to open the booking rather than merely name it.
		$this->assertSame([12], $applied->relatedBookingIds());
	}

	public function testACancellationIsCalledOutByNameInTheReport(): void {
		// Leaving the booking untouched matters most here, so the notice has to
		// say plainly that the user must act.
		$this->bookingMapper->method('findByReference')->willReturn($this->existing());

		$applied = $this->service->applyExtraction('alice', '<m3@example.com>', [$this->extracted('cancelled')]);

		$this->assertStringContainsString('CANCELLED', $applied->related[0]->description);
		$this->assertStringContainsString('yourself', $applied->related[0]->description);
	}

	public function testACancellationInAFirstMessageStillCreatesACancelledBooking(): void {
		$this->bookingMapper->method('findByReference')->willReturn(null);
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
		$this->bookingMapper->method('findByReference')->willReturnCallback(
			fn (string $u, string $type): ?Booking => $type === 'flight' ? $this->existing() : null,
		);
		$this->bookingMapper->expects($this->once())->method('insert');

		$applied = $this->service->applyExtraction('alice', '<m5@example.com>', [$this->extracted(), $hotel]);

		$this->assertSame(1, $applied->created);
		$this->assertCount(1, $applied->related);
	}
}
