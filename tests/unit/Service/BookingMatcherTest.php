<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Service\BookingMatcher;
use OCA\TravelManager\Service\Dto\BookingMatch;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCA\TravelManager\Service\Dto\MatchCandidate;
use PHPUnit\Framework\TestCase;

/**
 * The duplicate-detection rules. Pure — runs under the standalone bootstrap.
 *
 * The two named cases below are real emails the old (type, provider, reference)
 * key let through, and they are the reason this class exists; keep them.
 */
final class BookingMatcherTest extends TestCase {
	private BookingMatcher $matcher;

	protected function setUp(): void {
		parent::setUp();
		$this->matcher = new BookingMatcher();
	}

	/**
	 * @param array<array-key, mixed> $details
	 */
	private function incoming(
		string $type,
		?string $provider,
		?string $reference,
		?string $confirmation,
		array $details,
		?string $startDate,
	): ExtractedBooking {
		return new ExtractedBooking($type, $provider, $reference, $confirmation, 'confirmed', 'Some booking', $details, $startDate, null, null);
	}

	/**
	 * @param array<array-key, mixed> $details
	 */
	private function candidate(
		int $id,
		string $type,
		?string $provider,
		?string $reference,
		?string $confirmation,
		array $details,
		?string $startDate,
	): MatchCandidate {
		return new MatchCandidate($id, $type, $provider, $reference, $confirmation, $details, $startDate, 'confirmed');
	}

	/* ------------------------------------------------- the two observed misses */

	public function testAReferenceAndAConfirmationNumberAreTheSameKindOfClaim(): void {
		// Observed: one email called WDP1UANA the confirmation number, the next
		// called it the booking reference. Neither is wrong; only the set matches.
		$existing = $this->candidate(
			12,
			'accommodation',
			'Goelett',
			'S4RHNGWR',
			'WDP1UANA',
			['propertyName' => 'Bo18 Superior ***'],
			'2026-09-15T14:00:00',
		);
		$second = $this->incoming(
			'accommodation',
			'Goelett',
			'WDP1UANA',
			null,
			['propertyName' => 'Bo18 Superior'],
			'2026-09-15T00:00:00',
		);

		$match = $this->matcher->match($second, [$existing]);

		$this->assertNotNull($match);
		$this->assertTrue($match->decisive);
		$this->assertSame(BookingMatch::REASON_IDENTIFIER, $match->reason);
		$this->assertSame(12, $match->candidate->id);
	}

	public function testTheBrokerAndTheRentalDeskAreTheSameBooking(): void {
		// Observed: provider was "Holiday Autos" in one email and "GOLDCAR" in
		// the next, while both identifiers and both dates agreed exactly.
		$details = ['supplier' => 'Holiday Autos', 'rentalCompany' => 'GOLDCAR'];
		$existing = $this->candidate(7, 'car_rental', 'Holiday Autos', 'ES867772590', '29276863', $details, '2026-06-24T18:00:00');
		$second = $this->incoming('car_rental', 'GOLDCAR', 'ES867772590', '29276863', $details, '2026-06-24T18:00:00');

		$match = $this->matcher->match($second, [$existing]);

		$this->assertNotNull($match);
		$this->assertTrue($match->decisive);
		$this->assertSame(7, $match->candidate->id);
	}

	/* ------------------------------------------------------------- identifiers */

	public function testPunctuationAndCaseDoNotMakeANewBooking(): void {
		$existing = $this->candidate(1, 'car_rental', 'Goldcar', 'ES867772590', null, [], '2026-06-24T18:00:00');
		$second = $this->incoming('car_rental', 'Goldcar SL', 'es867-772 590', null, [], '2026-06-24T18:00:00');

		$this->assertTrue($this->matcher->match($second, [$existing])?->decisive);
	}

	public function testAnIdentifierMatchSurvivesTheDatesChanging(): void {
		// A rebooking keeps its reference and changes everything else, so dates
		// corroborate but must never veto.
		$existing = $this->candidate(1, 'flight', 'KLM', 'YGUE6T', null, [], '2026-07-25T08:35:00');
		$second = $this->incoming('flight', 'KLM', 'YGUE6T', null, [], '2026-08-02T08:35:00');

		$this->assertTrue($this->matcher->match($second, [$existing])?->decisive);
	}

	public function testAShortNumericIdentifierAloneIsNotEnoughToSuppress(): void {
		// "12345" is as likely a room number as a booking; storing the booking
		// and flagging it is the recoverable mistake.
		$existing = $this->candidate(1, 'accommodation', 'Hotel Sol', '12345', null, [], '2026-07-25T15:00:00');
		$second = $this->incoming('accommodation', 'Pension Mar', '12345', null, [], '2026-11-02T15:00:00');

		$match = $this->matcher->match($second, [$existing]);

		$this->assertNotNull($match);
		$this->assertFalse($match->decisive);
		$this->assertSame(BookingMatch::REASON_POSSIBLE, $match->reason);
	}

	public function testAShortNumericIdentifierWithTheSameDayIsEnough(): void {
		$existing = $this->candidate(1, 'accommodation', 'Hotel Sol', '12345', null, [], '2026-07-25T15:00:00');
		$second = $this->incoming('accommodation', 'Hotel Sol', '12345', null, [], '2026-07-25T00:00:00');

		$this->assertTrue($this->matcher->match($second, [$existing])?->decisive);
	}

	public function testPlaceholderIdentifiersAreNotIdentifiers(): void {
		$existing = $this->candidate(1, 'flight', 'KLM', 'PENDING', null, [], '2026-07-25T08:35:00');
		$second = $this->incoming('flight', 'Ryanair', 'PENDING', null, [], '2026-11-02T08:35:00');

		$this->assertNull($this->matcher->match($second, [$existing]));
	}

	/* ---------------------------------------------------------------- itinerary */

	public function testTheSameStayOnTheSameDayMatchesWithNoReferenceEitherSide(): void {
		$existing = $this->candidate(3, 'accommodation', null, null, null, ['propertyName' => 'Bo18 Superior'], '2026-09-15T14:00:00');
		$second = $this->incoming('accommodation', 'Bo18 Superior', null, null, [], '2026-09-15T00:00:00');

		$match = $this->matcher->match($second, [$existing]);

		$this->assertNotNull($match);
		$this->assertTrue($match->decisive);
		$this->assertSame(BookingMatch::REASON_ITINERARY, $match->reason);
	}

	public function testReferencesThatDisagreeOutvoteTheOperatorAndTheDay(): void {
		// Two rooms at one hotel for one night are two bookings, and the two
		// references say so. Store both, flag the resemblance.
		$existing = $this->candidate(3, 'accommodation', 'Hotel Sol', 'AAA111', null, [], '2026-09-15T14:00:00');
		$second = $this->incoming('accommodation', 'Hotel Sol', 'BBB222', null, [], '2026-09-15T14:00:00');

		$match = $this->matcher->match($second, [$existing]);

		$this->assertNotNull($match);
		$this->assertFalse($match->decisive);
		$this->assertStringContainsString('AAA111', $match->evidence);
	}

	public function testTwoOneWaysOnOneAirlineOnOneDayAreNotOneBooking(): void {
		$existing = $this->candidate(4, 'flight', 'KLM', null, null, [
			'segments' => [['flightNumber' => 'KL1234', 'origin' => 'AMS', 'destination' => 'LHR']],
		], '2026-07-25T08:35:00');
		$second = $this->incoming('flight', 'KLM', null, null, [
			'segments' => [['flightNumber' => 'KL5678', 'origin' => 'LHR', 'destination' => 'AMS']],
		], '2026-07-25T19:00:00');

		$this->assertNull($this->matcher->match($second, [$existing]));
	}

	public function testTheSameFlightOnTheSameDayIsOneBooking(): void {
		$segments = ['segments' => [['flightNumber' => 'KL 1234', 'origin' => 'AMS', 'destination' => 'LHR']]];
		$existing = $this->candidate(4, 'flight', 'KLM', null, null, $segments, '2026-07-25T08:35:00');
		$second = $this->incoming('flight', 'KLM Royal Dutch Airlines', null, null, $segments, '2026-07-25T08:35:00');

		$this->assertSame(BookingMatch::REASON_ITINERARY, $this->matcher->match($second, [$existing])?->reason);
	}

	/* -------------------------------------------------------------- non-matches */

	public function testADifferentTypeIsNeverAMatch(): void {
		$existing = $this->candidate(1, 'accommodation', 'Sixt', 'ABC123', null, [], '2026-07-25T15:00:00');
		$second = $this->incoming('car_rental', 'Sixt', 'ABC123', null, [], '2026-07-25T15:00:00');

		$this->assertNull($this->matcher->match($second, [$existing]));
	}

	public function testUnrelatedBookingsInTheWindowAreLeftAlone(): void {
		$existing = $this->candidate(1, 'accommodation', 'Hotel Sol', 'AAA111', null, [], '2026-09-15T14:00:00');
		$second = $this->incoming('accommodation', 'Pension Mar', 'BBB222', null, [], '2026-09-18T14:00:00');

		$this->assertNull($this->matcher->match($second, [$existing]));
	}

	public function testADecisiveMatchWinsOverAnEarlierWeakOne(): void {
		$weak = $this->candidate(1, 'accommodation', 'Pension Mar', '12345', null, [], '2026-11-02T15:00:00');
		$real = $this->candidate(2, 'accommodation', 'Hotel Sol', 'WDP1UANA', null, [], '2026-07-25T15:00:00');
		$second = $this->incoming('accommodation', 'Hotel Sol', '12345', 'WDP1UANA', [], '2026-07-25T15:00:00');

		$match = $this->matcher->match($second, [$weak, $real]);

		$this->assertNotNull($match);
		$this->assertTrue($match->decisive);
		$this->assertSame(2, $match->candidate->id);
	}

	public function testAnOperatorNameThatIsOnlyACategoryMatchesNothing(): void {
		// "Hotel" is not how you tell one hotel from another, so it must not
		// stand in for "Hotel Sol" when the prefix rule is applied.
		$existing = $this->candidate(5, 'accommodation', 'Hotel Sol', null, null, [], '2026-09-15T14:00:00');
		$second = $this->incoming('accommodation', 'Hotel', null, null, [], '2026-09-15T14:00:00');

		$this->assertNull($this->matcher->match($second, [$existing]));
	}

	public function testAnOperatorNameThatIsHowTheOtherStartsDoesMatch(): void {
		$existing = $this->candidate(5, 'flight', 'KLM Royal Dutch Airlines', null, null, [
			'segments' => [['flightNumber' => 'KL1234']],
		], '2026-07-25T08:35:00');
		$second = $this->incoming('flight', 'KLM', null, null, [
			'segments' => [['flightNumber' => 'KL1234']],
		], '2026-07-25T08:35:00');

		$this->assertTrue($this->matcher->match($second, [$existing])?->decisive);
	}

	/* ------------------------------------------------------------- unit helpers */

	public function testNormalizeIdentifierRejectsWhatCannotIdentifyABooking(): void {
		$this->assertSame('ES867772590', $this->matcher->normalizeIdentifier('es867-772 590'));
		$this->assertNull($this->matcher->normalizeIdentifier('12'));
		$this->assertNull($this->matcher->normalizeIdentifier('  '));
		$this->assertNull($this->matcher->normalizeIdentifier(null));
	}

	public function testProviderNamesGatherBothRolesFromTheDetails(): void {
		$names = $this->matcher->providerNames('car_rental', 'Holiday Autos', [
			'supplier' => 'Holiday Autos',
			'rentalCompany' => 'Goldcar SL',
		]);

		$this->assertSame(['holiday autos', 'goldcar'], $names);
	}
}
