<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Exception\ExtractionException;
use OCA\TravelManager\Service\Dto\ExtractionIssue;
use OCA\TravelManager\Service\ExtractionService;
use PHPUnit\Framework\TestCase;

final class ExtractionServiceTest extends TestCase {
	private ExtractionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ExtractionService();
	}

	public function testParsesFlightWithPassengersAndSegments(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'provider' => 'KLM',
				'booking_reference' => 'YGUE6T',
				'confirmation_number' => '29276863',
				'status' => 'confirmed',
				'title' => 'AMS → SOU',
				'details' => [
					'passengers' => [
						['name' => 'Jane Doe', 'frequentFlyer' => 'SK123', 'baggage' => '1x23kg'],
					],
					'segments' => [[
						'carrier' => 'KLM',
						'flightNumber' => 'KL1069',
						'origin' => 'AMS',
						'destination' => 'SOU',
						'departureLocal' => '2026-07-25T08:35:00',
						'departureTimezone' => 'Europe/Amsterdam',
						'arrivalLocal' => '2026-07-25T08:45:00',
					]],
				],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertCount(1, $bookings);
		$booking = $bookings[0];
		$this->assertSame('flight', $booking->type);
		$this->assertSame('KLM', $booking->provider);
		$this->assertSame('YGUE6T', $booking->bookingReference);
		$this->assertSame('29276863', $booking->confirmationNumber);
		// Details are passed through, with dates normalized.
		$this->assertSame('Jane Doe', $booking->details['passengers'][0]['name']);
		$this->assertSame('SK123', $booking->details['passengers'][0]['frequentFlyer']);
		$this->assertSame('2026-07-25T08:35:00', $booking->details['segments'][0]['departureLocal']);
		$this->assertSame('2026-07-25T08:45:00', $booking->details['segments'][0]['arrivalLocal']);
		// Span derived from the leg times.
		$this->assertSame('2026-07-25T08:35:00', $booking->startDate);
		$this->assertSame('2026-07-25T08:45:00', $booking->endDate);
	}

	public function testFlightSpanCoversAllLegs(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'details' => ['segments' => [
					['departureLocal' => '2026-08-01T08:00:00', 'arrivalLocal' => '2026-08-01T10:00:00'],
					['departureLocal' => '2026-08-10T18:00:00', 'arrivalLocal' => '2026-08-10T20:00:00'],
				]],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertCount(2, $bookings[0]->details['segments']);
		$this->assertSame('2026-08-01T08:00:00', $bookings[0]->startDate);
		$this->assertSame('2026-08-10T20:00:00', $bookings[0]->endDate);
	}

	public function testDropsFlightLegWithoutValidDeparture(): void {
		// Anti-hallucination: a leg without a parseable departure is discarded;
		// a flight left with no legs is dropped entirely.
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'details' => ['segments' => [['departureLocal' => 'sometime', 'origin' => 'OSL']]],
			]],
		]);

		$result = $this->service->parseAndValidate($json);

		$this->assertSame([], $result->bookings);
		// ...but the rejection is reported rather than swallowed.
		$this->assertSame(1, $result->droppedCount());
		$this->assertSame(ExtractionIssue::REASON_MISSING_DEPARTURE, $result->issues[0]->reason);
	}

	public function testReportsPartiallyDroppedFlightLegs(): void {
		// The booking survives on its good leg; losing the other one silently
		// would misreport the trip's span, so it is flagged.
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'title' => 'OSL → LHR',
				'details' => ['segments' => [
					['departureLocal' => '2026-08-01T08:00:00'],
					['departureLocal' => 'next tuesday'],
				]],
			]],
		]);

		$result = $this->service->parseAndValidate($json);

		$this->assertCount(1, $result->bookings);
		$this->assertCount(1, $result->bookings[0]->details['segments']);
		$this->assertSame(0, $result->droppedCount());
		$this->assertSame(ExtractionIssue::REASON_PARTIAL_SEGMENTS, $result->issues[0]->reason);
		$this->assertStringContainsString('kept 1 of 2', $result->issues[0]->description);
	}

	public function testParsesCarRentalWithSupplierAndPeriod(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'car_rental',
				'provider' => 'Holiday Autos',
				'booking_reference' => 'ES867772590',
				'confirmation_number' => '29276863',
				'details' => [
					'supplier' => 'Holiday Autos',
					'rentalCompany' => 'Europcar',
					'carType' => 'Compact - VW Golf or similar',
					'carFeatures' => ['automatic', 'air conditioning'],
					'driver' => ['name' => 'Jane Doe'],
					'pickup' => ['location' => 'Gran Canaria - Airport', 'local' => '2026-06-24T18:00:00'],
					'dropoff' => ['location' => 'Gran Canaria - Airport', 'local' => '2026-06-28T12:30:00'],
				],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertCount(1, $bookings);
		$booking = $bookings[0];
		$this->assertSame('car_rental', $booking->type);
		$this->assertSame('Europcar', $booking->details['rentalCompany']);
		$this->assertSame(['automatic', 'air conditioning'], $booking->details['carFeatures']);
		$this->assertSame('2026-06-24T18:00:00', $booking->details['pickup']['local']);
		$this->assertSame('2026-06-24T18:00:00', $booking->startDate);
		$this->assertSame('2026-06-28T12:30:00', $booking->endDate);
	}

	public function testDropsCarRentalWithoutPickup(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'car_rental',
				'details' => ['rentalCompany' => 'Hertz', 'pickup' => ['location' => 'OSL', 'local' => 'on arrival']],
			]],
		]);

		$result = $this->service->parseAndValidate($json);

		$this->assertSame([], $result->bookings);
		$this->assertSame(ExtractionIssue::REASON_MISSING_PICKUP, $result->issues[0]->reason);
		// The unusable raw value is echoed back — that string is what you tune
		// the prompt against.
		$this->assertStringContainsString('on arrival', $result->issues[0]->description);
	}

	public function testParsesAccommodationWithGuests(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'accommodation',
				'provider' => 'Booking.com',
				'details' => [
					'propertyName' => 'Hotel Sol',
					'checkIn' => ['local' => '2026-07-25T15:00:00'],
					'checkOut' => ['local' => '2026-07-28T11:00:00'],
					'roomType' => 'Double',
					'guests' => [['name' => 'Jane Doe']],
				],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertCount(1, $bookings);
		$booking = $bookings[0];
		$this->assertSame('accommodation', $booking->type);
		$this->assertSame('Hotel Sol', $booking->details['propertyName']);
		$this->assertSame('2026-07-25T15:00:00', $booking->startDate);
		$this->assertSame('2026-07-28T11:00:00', $booking->endDate);
	}

	public function testDropsAccommodationWithoutCheckIn(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'accommodation',
				'details' => ['propertyName' => 'Hotel Sol'],
			]],
		]);

		$result = $this->service->parseAndValidate($json);

		$this->assertSame([], $result->bookings);
		$this->assertSame(ExtractionIssue::REASON_MISSING_CHECKIN, $result->issues[0]->reason);
	}

	public function testDistinguishesAnEmptyResultFromARejectedOne(): void {
		// The whole point of reporting issues: both of these yield zero bookings,
		// but only the second one is worth retrying or tuning the prompt against.
		$nothingFound = $this->service->parseAndValidate('{"bookings": []}');
		$this->assertSame([], $nothingFound->bookings);
		$this->assertSame([], $nothingFound->issues);
		$this->assertSame(0, $nothingFound->droppedCount());

		$rejected = $this->service->parseAndValidate((string)json_encode([
			'bookings' => [['type' => 'accommodation', 'details' => ['propertyName' => 'Hotel Sol']]],
		]));
		$this->assertSame([], $rejected->bookings);
		$this->assertSame(1, $rejected->droppedCount());
	}

	public function testAcceptsDateOnlyCheckIn(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'accommodation',
				'details' => ['checkIn' => ['local' => '2026-09-01'], 'checkOut' => ['local' => '2026-09-03']],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		// A date-only value normalizes to midnight local wall-clock.
		$this->assertSame('2026-09-01T00:00:00', $bookings[0]->details['checkIn']['local']);
		$this->assertSame('2026-09-01T00:00:00', $bookings[0]->startDate);
	}

	public function testPreservesUnknownDetailFields(): void {
		// The JSON details are passed through, so new prompt fields flow to the
		// UI without code changes.
		$json = json_encode([
			'bookings' => [[
				'type' => 'car_rental',
				'details' => [
					'pickup' => ['local' => '2026-06-24T18:00:00'],
					'excessInsurance' => 'included',
					'depositAmount' => '1200 EUR',
				],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertSame('included', $bookings[0]->details['excessInsurance']);
		$this->assertSame('1200 EUR', $bookings[0]->details['depositAmount']);
	}

	public function testDropsUnknownBookingType(): void {
		$json = json_encode([
			'bookings' => [
				['type' => 'event', 'details' => ['segments' => [['departureLocal' => '2026-07-15T09:30:00']]]],
				['type' => 'flight', 'details' => ['segments' => [['departureLocal' => '2026-07-15T09:30:00']]]],
			],
		]);

		$result = $this->service->parseAndValidate($json);

		$this->assertCount(1, $result->bookings);
		$this->assertSame('flight', $result->bookings[0]->type);
		// The unsupported type is reported, not silently ignored.
		$this->assertSame(ExtractionIssue::REASON_UNKNOWN_TYPE, $result->issues[0]->reason);
		$this->assertStringContainsString('event', $result->issues[0]->description);
	}

	public function testStripsMarkdownFences(): void {
		$raw = "```json\n{\"bookings\": [{\"type\": \"accommodation\", \"details\": {\"checkIn\": {\"local\": \"2026-09-01\"}}}]}\n```";

		$bookings = $this->service->parseAndValidate($raw)->bookings;

		$this->assertCount(1, $bookings);
		$this->assertSame('accommodation', $bookings[0]->type);
	}

	public function testIgnoresProseAroundJson(): void {
		$raw = "Sure! Here is the data you asked for:\n{\"bookings\": []}\nLet me know if you need anything else.";

		$this->assertSame([], $this->service->parseAndValidate($raw)->bookings);
	}

	public function testCancellationStatusPreserved(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'car_rental',
				'status' => 'cancelled',
				'details' => ['pickup' => ['local' => '2026-07-15T09:30:00']],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertSame('cancelled', $bookings[0]->status);
	}

	public function testInvalidStatusFallsBackToConfirmed(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'status' => 'nonsense',
				'details' => ['segments' => [['departureLocal' => '2026-07-15T09:30:00']]],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json)->bookings;

		$this->assertSame('confirmed', $bookings[0]->status);
	}

	public function testRepairsResponseMissingClosingBraces(): void {
		// The observed intermittent failure: a car-rental response exactly one
		// `}` short. Everything in it is correct, so throwing it away is waste.
		$truncated = '{"bookings": [{"type": "car_rental", "provider": "Holiday Autos", '
			. '"booking_reference": "ES867772590", "details": {"rentalCompany": "GOLDCAR", '
			. '"driver": {"name": "Martin Hammer"}, '
			. '"pickup": {"location": "Gran Canaria Airport", "local": "2026-06-24T18:00:00"}, '
			. '"dropoff": {"location": "Gran Canaria Airport", "local": "2026-06-28T12:30:00"}}]}';

		$result = $this->service->parseAndValidate($truncated);

		$this->assertCount(1, $result->bookings);
		$this->assertSame('car_rental', $result->bookings[0]->type);
		$this->assertSame('GOLDCAR', $result->bookings[0]->details['rentalCompany']);
		$this->assertSame('2026-06-24T18:00:00', $result->bookings[0]->startDate);
		// The repair is recorded, never silent.
		$this->assertSame(ExtractionIssue::REASON_REPAIRED_JSON, $result->issues[0]->reason);
		$this->assertFalse($result->issues[0]->dropped);
		$this->assertStringContainsString('inserted 1 missing closing character', $result->issues[0]->description);
	}

	public function testInsertsAClosingBraceInTheMiddleNotJustAtTheEnd(): void {
		// The real failure closes the bookings array while a booking is still
		// open, so the missing `}` belongs before the `]` — appending at the end
		// would produce "…}]}}" and still not parse.
		$repair = $this->service->repairJson('{"bookings": [{"type": "flight"]}');

		$this->assertSame('{"bookings": [{"type": "flight"}]}', $repair['json']);
		$this->assertNotNull($repair['note']);
	}

	public function testClosesNestedContainersInTheRightOrder(): void {
		// Needs "}]}" — a plain depth counter would emit "}}}".
		$repair = $this->service->repairJson('{"bookings": [{"type": "flight"');

		$this->assertSame('{"bookings": [{"type": "flight"}]}', $repair['json']);
		$this->assertNotNull($repair['note']);
	}

	public function testReportsNoRepairForWellFormedResponses(): void {
		$this->assertNull($this->service->repairJson('{"bookings": []}')['note']);
		$this->assertSame([], $this->service->parseAndValidate('{"bookings": []}')->issues);
	}

	public function testRefusesToRepairAResponseCutOffInsideAString(): void {
		// Closing the quote would turn a half-written value into a plausible
		// whole one — worse than failing.
		$this->expectException(ExtractionException::class);
		$this->service->parseAndValidate('{"bookings": [{"type": "car_rental", "title": "Gran Cana');
	}

	public function testRefusesARepairThatWouldStillNotParse(): void {
		// A dangling key: appending braces yields {"bookings": [{"type": }]}.
		$this->expectException(ExtractionException::class);
		$this->service->parseAndValidate('{"bookings": [{"type":');
	}

	private const FLIGHT = '{"type": "flight", "details": {"segments": [{"departureLocal": "2026-07-15T09:30:00"}]}}';
	private const BUS = '{"type": "bus", "details": {"segments": [{"departureLocal": "2026-07-16T08:00:00"}]}}';

	public function testAnExtraClosingBraceBetweenBookingsDoesNotEndTheResponse(): void {
		// Read as "the model forgot `]`", the extra `}` closed the root and the
		// bus after it vanished without an issue saying so.
		$result = $this->service->parseAndValidate('{"bookings": [' . self::FLIGHT . '}, ' . self::BUS . ']}');

		$this->assertSame(['flight', 'bus'], array_map(static fn ($b) => $b->type, $result->bookings));
		$this->assertCount(1, $result->issues);
		$this->assertSame(ExtractionIssue::REASON_REPAIRED_JSON, $result->issues[0]->reason);
		$this->assertStringContainsString('removed 1 stray closing character', $result->issues[0]->description);
	}

	public function testAnExtraClosingBracketInsideABookingDoesNotEndTheResponse(): void {
		$flight = '{"type": "flight", "details": {"segments": [{"departureLocal": "2026-07-15T09:30:00"}]]}}';
		$result = $this->service->parseAndValidate('{"bookings": [' . $flight . ', ' . self::BUS . ']}');

		$this->assertSame(['flight', 'bus'], array_map(static fn ($b) => $b->type, $result->bookings));
	}

	public function testTextThatCannotBeReadAfterARepairIsReportedAsLost(): void {
		// Neither reading of the extra `}` parses, so the flight is kept — and
		// the rest is a drop, not a clean run.
		$result = $this->service->parseAndValidate('{"bookings": [' . self::FLIGHT . '}, {"type": bus"}]}');

		$this->assertCount(1, $result->bookings);
		$this->assertSame(
			[ExtractionIssue::REASON_REPAIRED_JSON, ExtractionIssue::REASON_INCOMPLETE_JSON],
			$result->reasonSlugs(),
		);
		$this->assertSame(1, $result->droppedCount());
	}

	public function testProseAfterARepairedResponseIsNotALoss(): void {
		$flight = '{"type": "flight", "details": {"segments": [{"departureLocal": "2026-07-15T09:30:00"}]}';
		$result = $this->service->parseAndValidate('{"bookings": [' . $flight . ']} I hope this helps!');

		$this->assertCount(1, $result->bookings);
		$this->assertSame([ExtractionIssue::REASON_REPAIRED_JSON], $result->reasonSlugs());
	}

	public function testAResponseCutOffBeforeAnyBookingIsAFailureNotNoBooking(): void {
		// Closing it gives {"bookings": []}, which reads as an email with nothing
		// in it and would never be retried.
		$this->expectException(ExtractionException::class);
		$this->expectExceptionMessage('incomplete');
		$this->service->parseAndValidate('{"bookings": [');
	}

	public function testAResponseCutOffAfterABookingKeepsItAndReportsTheRest(): void {
		foreach (['{"bookings": [' . self::FLIGHT, '{"bookings": [' . self::FLIGHT . ', '] as $cut) {
			$result = $this->service->parseAndValidate($cut);

			$this->assertCount(1, $result->bookings, $cut);
			$this->assertContains(ExtractionIssue::REASON_INCOMPLETE_JSON, $result->reasonSlugs(), $cut);
			$this->assertSame(1, $result->droppedCount(), $cut);
		}
	}

	public function testAResponseOnlyMissingItsRootBraceLosesNothing(): void {
		$result = $this->service->parseAndValidate('{"bookings": [' . self::FLIGHT . ']');

		$this->assertCount(1, $result->bookings);
		$this->assertSame([ExtractionIssue::REASON_REPAIRED_JSON], $result->reasonSlugs());
		$this->assertSame(0, $result->droppedCount());
	}

	public function testARepeatedBookingsKeyIsRefused(): void {
		// json_decode keeps the last value: zero bookings, zero issues.
		$this->expectException(ExtractionException::class);
		$this->expectExceptionMessage('"bookings"');
		$this->service->parseAndValidate('{"bookings": [' . self::FLIGHT . '], "bookings": []}');
	}

	public function testARepeatedKeyInsideABookingIsRefused(): void {
		$this->expectException(ExtractionException::class);
		$this->expectExceptionMessage('"type"');
		$this->service->parseAndValidate('{"bookings": [{"type": "flight", "type": "bus", "details": {}}]}');
	}

	public function testTheSameKeyInSiblingObjectsIsNotARepeat(): void {
		$result = $this->service->parseAndValidate('{"bookings": [' . self::FLIGHT . ', ' . self::BUS . ']}');

		$this->assertCount(2, $result->bookings);
		$this->assertSame([], $result->issues);
	}

	public function testThrowsOnNonJson(): void {
		$this->expectException(ExtractionException::class);
		$this->service->parseAndValidate('I could not find any bookings.');
	}

	public function testThrowsWhenBookingsKeyMissing(): void {
		$this->expectException(ExtractionException::class);
		$this->service->parseAndValidate('{"results": []}');
	}

	public function testNormalizeDateAcceptedFormats(): void {
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15T09:30:00'));
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15 09:30:00'));
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15T09:30'));
		$this->assertSame('2026-07-15T00:00:00', $this->service->normalizeDate('2026-07-15'));
		$this->assertNull($this->service->normalizeDate('15/07/2026 maybe'));
		$this->assertNull($this->service->normalizeDate(null));
	}

	/* ------------------------------------------------- field normalisation */

	public function testAnUnquotedNumericReferenceIsKeptAsAString(): void {
		// The model leaving the quotes off an all-digit reference is ordinary,
		// and json_decode makes it an int. It used to be discarded silently.
		$json = '{"bookings":[{"type":"bus","provider":"Flibco","booking_reference":37713299,'
			. '"confirmation_number":27874980,"details":{"segments":[{"departureLocal":"2026-07-25T08:35:00"}]}}]}';

		$booking = $this->service->parseAndValidate($json)->bookings[0];

		$this->assertSame('37713299', $booking->bookingReference);
		$this->assertSame('27874980', $booking->confirmationNumber);
	}

	public function testAnUnquotedNumberTooBigForAnIntKeepsEveryDigit(): void {
		// As a float it would come back rounded: a different, plausible number.
		$json = '{"bookings":[{"type":"flight","confirmation_number":12345678901234567890,'
			. '"details":{"segments":[{"departureLocal":"2026-07-25T08:35:00"}]}}]}';

		$this->assertSame('12345678901234567890', $this->service->parseAndValidate($json)->bookings[0]->confirmationNumber);
	}

	public function testADecimalIsNotAnIdentifier(): void {
		$json = '{"bookings":[{"type":"flight","booking_reference":1234.5,'
			. '"details":{"segments":[{"departureLocal":"2026-07-25T08:35:00"}]}}]}';

		$this->assertNull($this->service->parseAndValidate($json)->bookings[0]->bookingReference);
	}

	public function testNumericIdentifiersInsideDetailsBecomeStrings(): void {
		// BookingMatcher reads service and flight numbers as strings; a numeric
		// one used to switch its same-service guard off.
		$json = '{"bookings":[{"type":"bus","details":{'
			. '"passengers":[{"name":"Jane Doe","ticketNumber":37713299}],'
			. '"segments":[{"departureLocal":"2026-07-25T08:35:00","serviceNumber":506}]}}]}';

		$details = $this->service->parseAndValidate($json)->bookings[0]->details;

		$this->assertSame('506', $details['segments'][0]['serviceNumber']);
		$this->assertSame('37713299', $details['passengers'][0]['ticketNumber']);
		$this->assertArrayNotHasKey('flightNumber', $details['segments'][0], 'no field is invented');
	}

	public function testNormalizeDateKeepsTheWallClockOfAnIsoTimestamp(): void {
		// Dropped, never converted (V8): the clock is the one the email printed.
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15T09:30:00Z'));
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15T09:30:00+02:00'));
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15T09:30:00-0500'));
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15T09:30:00.000Z'));
		$this->assertSame('2026-07-15T09:30:00', $this->service->normalizeDate('2026-07-15 09:30+01'));
		$this->assertNull($this->service->normalizeDate('2026-07-15T09:30:00 CET'));
		$this->assertNull($this->service->normalizeDate('2026-02-30T09:30:00Z'), 'still rejects impossible dates');
	}

	public function testAReturnLegWithAUtcSuffixIsNotLost(): void {
		$json = json_encode(['bookings' => [[
			'type' => 'flight',
			'details' => ['segments' => [
				['departureLocal' => '2026-07-15T09:30:00'],
				['departureLocal' => '2026-07-22T18:05:00Z'],
			]],
		]]]);

		$result = $this->service->parseAndValidate($json);

		$this->assertCount(2, $result->bookings[0]->details['segments']);
		$this->assertSame('2026-07-22T18:05:00', $result->bookings[0]->details['segments'][1]['departureLocal']);
		$this->assertSame([], $result->issues);
	}

	public function testASegmentIssueQuotesWhatTheModelWrote(): void {
		// The raw value is what the prompt is tuned against, as car and hotel
		// issues already show theirs.
		$json = json_encode(['bookings' => [[
			'type' => 'flight',
			'details' => ['segments' => [
				['departureLocal' => '2026-07-15T09:30:00'],
				['departureLocal' => '22 July, evening'],
				['origin' => 'LHR'],
			]],
		]]]);

		$issue = $this->service->parseAndValidate($json)->issues[0];

		$this->assertSame(ExtractionIssue::REASON_PARTIAL_SEGMENTS, $issue->reason);
		$this->assertStringContainsString('(got "22 July, evening", no departureLocal)', $issue->description);
	}

	public function testADateOnlyArrivalDoesNotMoveTheStartToMidnight(): void {
		$json = json_encode(['bookings' => [[
			'type' => 'flight',
			'details' => ['segments' => [
				['departureLocal' => '2026-07-15T22:00:00', 'arrivalLocal' => '2026-07-15'],
			]],
		]]]);

		$booking = $this->service->parseAndValidate($json)->bookings[0];

		$this->assertSame('2026-07-15T22:00:00', $booking->startDate);
		$this->assertSame('2026-07-15T22:00:00', $booking->endDate);
	}

	public function testAnOvernightArrivalStillEndsTheJourney(): void {
		$json = json_encode(['bookings' => [[
			'type' => 'flight',
			'details' => ['segments' => [
				['departureLocal' => '2026-07-15T22:00:00', 'arrivalLocal' => '2026-07-16T06:10:00'],
			]],
		]]]);

		$booking = $this->service->parseAndValidate($json)->bookings[0];

		$this->assertSame('2026-07-15T22:00:00', $booking->startDate);
		$this->assertSame('2026-07-16T06:10:00', $booking->endDate);
	}

	public function testOneSegmentWrittenAsAnObjectIsOneLeg(): void {
		// PHP decodes the object to an array too, so it used to be walked as the
		// leg's fields and reported as "no segments".
		$json = '{"bookings":[{"type":"flight","details":{"segments":'
			. '{"flightNumber":"KL1069","departureLocal":"2026-07-25T08:35:00"}}}]}';

		$booking = $this->service->parseAndValidate($json)->bookings[0];

		$this->assertCount(1, $booking->details['segments']);
		$this->assertSame('KL1069', $booking->details['segments'][0]['flightNumber']);
	}

	public function testSegmentsKeyedByNameAreReadAsAList(): void {
		$json = '{"bookings":[{"type":"train","details":{"segments":{'
			. '"outbound":{"departureLocal":"2026-07-25T08:35:00"},'
			. '"return":{"departureLocal":"2026-07-28T17:00:00"}}}}]}';

		$this->assertCount(2, $this->service->parseAndValidate($json)->bookings[0]->details['segments']);
	}

	public function testASegmentThatIsNotAnObjectIsCountedNotIgnored(): void {
		$json = '{"bookings":[{"type":"flight","details":{"segments":["AMS-LHR 08:35"]}}]}';

		$issue = $this->service->parseAndValidate($json)->issues[0];

		$this->assertSame(ExtractionIssue::REASON_MISSING_DEPARTURE, $issue->reason);
		$this->assertStringNotContainsString('no segments', $issue->description);
		$this->assertStringContainsString('none of its 1 segment(s)', $issue->description);
		$this->assertStringContainsString('not an object', $issue->description);
	}

	public function testAllowedTypesContractCoversMvpTypes(): void {
		$this->assertSame(
			['flight', 'accommodation', 'car_rental', 'train', 'bus'],
			ExtractionService::ALLOWED_TYPES,
		);
	}

	/**
	 * Modelled on a real SNCB-NMBS International confirmation: two legs with a
	 * change at Rotterdam, the date stated once for the whole journey, and the
	 * service number printed after the leg it belongs to.
	 */
	public function testParsesTrainWithConnectingLegs(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'train',
				'provider' => 'Eurocity Direct',
				'booking_reference' => 'KDHWBQD',
				'status' => 'confirmed',
				'title' => 'Bruxelles-Midi → Utrecht Centraal',
				'details' => [
					'retailer' => 'SNCB-NMBS International',
					'passengers' => [['name' => 'Martin Hammer']],
					'segments' => [[
						'carrier' => 'Eurocity Direct',
						'serviceNumber' => '9567',
						'origin' => 'Bruxelles Midi',
						'destination' => 'Rotterdam Centraal',
						'departureLocal' => '2026-09-01T17:49:00',
						'arrivalLocal' => '2026-09-01T19:17:00',
						'fareClass' => 'Saver Adult Eurocity Direct 2nd Class',
					], [
						'carrier' => 'InterCity',
						'serviceNumber' => '2073',
						'origin' => 'Rotterdam Centraal',
						'destination' => 'Utrecht Centraal',
						'departureLocal' => '2026-09-01T19:35:00',
						'arrivalLocal' => '2026-09-01T20:12:00',
					]],
				],
			]],
		], JSON_THROW_ON_ERROR);

		$result = $this->service->parseAndValidate($json);
		$this->assertCount(1, $result->bookings);
		$this->assertSame([], $result->issues);

		$booking = $result->bookings[0];
		$this->assertSame('train', $booking->type);
		$this->assertSame('KDHWBQD', $booking->bookingReference);
		$this->assertSame('SNCB-NMBS International', $booking->details['retailer']);
		$this->assertCount(2, $booking->details['segments']);
		// The span covers the whole journey, first departure to last arrival.
		$this->assertSame('2026-09-01T17:49:00', $booking->startDate);
		$this->assertSame('2026-09-01T20:12:00', $booking->endDate);
	}

	/**
	 * Modelled on a real Easybook order summary: one leg, two passengers with
	 * their own ticket numbers and seats, no arrival time at all, and a seller
	 * (Easybook) distinct from the operator (Perdana Express).
	 */
	public function testParsesBusWithNoArrivalTime(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'bus',
				'provider' => 'Perdana Express',
				'booking_reference' => 'C9A4N',
				'confirmation_number' => 'EBMY-ON-2609024483',
				'status' => 'confirmed',
				'details' => [
					'retailer' => 'Easybook',
					'passengers' => [
						['name' => 'Najiba Ramly', 'ticketNumber' => 'EP26090008586'],
						['name' => 'Martin Hammer', 'ticketNumber' => 'EP26090008585'],
					],
					'segments' => [[
						'carrier' => 'Perdana Express',
						'serviceNumber' => '.KD-74',
						'origin' => 'Terminal Shahab Perdana (Alor Setar)',
						'destination' => 'Terminal Bas Kuala Besut',
						'departureLocal' => '2026-09-06T22:00:00',
						'arrivalLocal' => null,
						'seat' => '7B, 7C',
					]],
				],
			]],
		], JSON_THROW_ON_ERROR);

		$result = $this->service->parseAndValidate($json);
		$this->assertCount(1, $result->bookings);
		$this->assertSame([], $result->issues);

		$booking = $result->bookings[0];
		$this->assertSame('bus', $booking->type);
		$this->assertSame('EP26090008585', $booking->details['passengers'][1]['ticketNumber']);
		// No arrival: the span collapses onto the departure rather than being open-ended.
		$this->assertSame('2026-09-06T22:00:00', $booking->startDate);
		$this->assertSame('2026-09-06T22:00:00', $booking->endDate);
	}

	/**
	 * The Eurostar confirmation: one reference (HR272Y) covering an outbound and
	 * a return, two passengers, and a different coach and seat for each of them
	 * on each leg — the full four-way matrix a single seat field cannot hold.
	 */
	public function testKeepsEveryPassengersSeatOnEveryLeg(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'train',
				'provider' => 'Eurostar',
				'booking_reference' => 'HR272Y',
				'status' => 'confirmed',
				'details' => [
					'passengers' => [['name' => 'Martin Hammer'], ['name' => 'Najiba Ramly']],
					'segments' => [[
						'carrier' => 'Eurostar',
						'origin' => 'Brussels Midi / Zuid',
						'destination' => "London St Pancras Int'l",
						'departureLocal' => '2025-10-24T17:56:00',
						'arrivalLocal' => '2025-10-24T18:57:00',
						'fareClass' => 'Standard',
						'seats' => [
							['passenger' => 'Martin Hammer', 'coach' => '8', 'seat' => '18'],
							['passenger' => 'Najiba Ramly', 'coach' => '8', 'seat' => '17'],
						],
					], [
						'carrier' => 'Eurostar',
						'origin' => "London St Pancras Int'l",
						'destination' => 'Brussels Midi / Zuid',
						'departureLocal' => '2025-10-26T17:04:00',
						'arrivalLocal' => '2025-10-26T20:05:00',
						'fareClass' => 'Standard',
						'seats' => [
							['passenger' => 'Martin Hammer', 'coach' => '16', 'seat' => '54'],
							['passenger' => 'Najiba Ramly', 'coach' => '16', 'seat' => '53'],
						],
					]],
				],
			]],
		], JSON_THROW_ON_ERROR);

		$result = $this->service->parseAndValidate($json);
		$this->assertCount(1, $result->bookings);
		$booking = $result->bookings[0];

		// One reference covering both directions is one booking, two legs.
		$this->assertCount(2, $booking->details['segments']);
		$this->assertSame('2025-10-24T17:56:00', $booking->startDate);
		$this->assertSame('2025-10-26T20:05:00', $booking->endDate);
		// All four assignments survive, not one per leg.
		$this->assertSame('17', $booking->details['segments'][0]['seats'][1]['seat']);
		$this->assertSame('16', $booking->details['segments'][1]['seats'][0]['coach']);
	}

	public function testBuildPromptAsksForSeatsPerPassengerPerLeg(): void {
		$prompt = $this->service->buildPrompt('Coach 8 - Seat 18');
		$this->assertStringContainsString('Seats are per passenger AND per leg', $prompt);
		$this->assertStringContainsString('"seats"', $prompt);
	}

	public function testBuildPromptPlacesASeatPrintedUnderAPassenger(): void {
		// Observed miss: a coach email prints the seat inside each passenger's own
		// block; with no seat field on a passenger the seats were dropped outright
		// rather than attached to the leg.
		$prompt = $this->service->buildPrompt('Passenger Name: Martin Hammer  Seat Number: 25');
		$this->assertStringContainsString('There is no seat field on a passenger', $prompt);
		$this->assertStringContainsString('per-passenger block is authoritative', $prompt);
	}

	public function testBuildPromptRanksIdentifiersByRole(): void {
		// Observed miss: nine identifier-shaped values, and the boarding code —
		// the one you show to board — lost to the seller's order code.
		$prompt = $this->service->buildPrompt('Boarding Code: Y8L3E, Order Code: EBMY-ON-2609057294');
		$this->assertStringContainsString('the traveller presents in order to travel', $prompt);
		$this->assertStringContainsString('identifying the order or transaction with the seller', $prompt);
	}

	public function testKeepsBothIdentifierRolesOnACoachBooking(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'bus',
				'provider' => 'SANI (HKPS)',
				'booking_reference' => 'Y8L3E',
				'confirmation_number' => 'EBMY-ON-2609057294',
				'status' => 'confirmed',
				'details' => [
					'retailer' => 'Easybook',
					'passengers' => [
						['name' => 'Martin Hammer', 'ticketNumber' => 'SNI26090025483'],
						['name' => 'najiba ramly', 'ticketNumber' => 'SNI26090025484'],
					],
					'segments' => [[
						'carrier' => 'SANI (HKPS)',
						'serviceNumber' => 'T60',
						'origin' => 'Terminal Bas Kuala Besut',
						'destination' => 'TBS (Terminal Bersepadu Selatan)',
						'departureLocal' => '2026-09-11T13:30:00',
						// From the per-passenger blocks, which pair them the other way
						// round from the summary line above them.
						'seats' => [
							['passenger' => 'Martin Hammer', 'seat' => '25'],
							['passenger' => 'najiba ramly', 'seat' => '24'],
						],
					]],
				],
			]],
		], JSON_THROW_ON_ERROR);

		$result = $this->service->parseAndValidate($json);
		$booking = $result->bookings[0];
		$this->assertSame('Y8L3E', $booking->bookingReference);
		$this->assertSame('EBMY-ON-2609057294', $booking->confirmationNumber);
		$this->assertSame('25', $booking->details['segments'][0]['seats'][0]['seat']);
	}

	public function testDropsTrainWithNoParseableDeparture(): void {
		// The anti-hallucination rule is the same one flights get, and it must
		// report the type it dropped rather than saying "flight" for everything.
		$json = json_encode([
			'bookings' => [[
				'type' => 'train',
				'title' => 'Utrecht → Berlin',
				'details' => ['segments' => [['origin' => 'Utrecht', 'departureLocal' => 'next Tuesday']]],
			]],
		], JSON_THROW_ON_ERROR);

		$result = $this->service->parseAndValidate($json);
		$this->assertSame([], $result->bookings);
		$this->assertCount(1, $result->issues);
		$this->assertSame(ExtractionIssue::REASON_MISSING_DEPARTURE, $result->issues[0]->reason);
		$this->assertStringContainsString('train dropped', $result->issues[0]->description);
	}

	public function testBuildPromptRulesOutNonBookingIdentifiers(): void {
		// Both observed rail/coach emails carry an identifier belonging to
		// something other than the booking — a customer number that never
		// changes, and a service number shared by everyone on the route. Either
		// one reaching an identifier field would merge unrelated bookings.
		$prompt = $this->service->buildPrompt('Your customer number: 42875099');
		$this->assertStringContainsString('must name THIS booking', $prompt);
		$this->assertStringContainsString('loyalty or frequent-flyer number', $prompt);
		$this->assertStringContainsString('segments[].serviceNumber', $prompt);
	}

	public function testBuildPromptForbidsPersonalAttributes(): void {
		// Real coach tickets list nationality, gender and mobile numbers per
		// passenger, and validateDetails passes unknown fields straight through
		// to the database — so the prompt is the gate.
		$prompt = $this->service->buildPrompt('Nationality: Malaysia, Gender: Female');
		$this->assertStringContainsString('Extract traveller names only', $prompt);
	}

	public function testBuildPromptExplainsAttachmentSections(): void {
		// The heading is MessageText::section's; the two must agree.
		$prompt = $this->service->buildPrompt("Ticket attached.\n\n--- ATTACHMENT: e-ticket.pdf (PDF, 1 page) ---\nMH1204");
		$this->assertStringContainsString('"--- ATTACHMENT: <name> ---"', $prompt);
		$this->assertStringContainsString('separates columns of a table', $prompt);
		$this->assertStringContainsString('return it once', $prompt);
	}

	public function testBuildPromptKeepsTicketNumbersOnThePassenger(): void {
		// Beside a PNR an e-ticket number in confirmation_number becomes a
		// matching key (Amadeus); alone it is the booking's only reference (Flibco).
		$prompt = $this->service->buildPrompt('Ticket number: 232 2486113027');
		$this->assertStringContainsString('always goes in that passenger\'s ticketNumber', $prompt);
		$this->assertStringContainsString('the ticket number is NOT an identifier', $prompt);
		$this->assertStringContainsString('it is the booking_reference as well', $prompt);
	}

	public function testBuildPromptDoesNotReadAFareClassAsACabin(): void {
		$prompt = $this->service->buildPrompt('Class: Q');
		$this->assertStringContainsString('booking class letter such as "Class: Q" is not a cabin', $prompt);
	}

	public function testBuildPromptContainsEmailAndSchema(): void {
		$prompt = $this->service->buildPrompt('Your flight is confirmed.', 'Booking confirmation');
		$this->assertStringContainsString('Your flight is confirmed.', $prompt);
		$this->assertStringContainsString('Booking confirmation', $prompt);
		$this->assertStringContainsString('"bookings"', $prompt);
		$this->assertStringContainsString('confirmation_number', $prompt);
		$this->assertStringContainsString('Do NOT convert timezones', $prompt);
	}

	public function testBuildPromptDemandsBalancedJson(): void {
		// Observed failure mode: a response one closing brace short, which no
		// amount of correct content can rescue.
		$prompt = $this->service->buildPrompt('Your car rental is confirmed.');
		$this->assertStringContainsString('valid JSON', $prompt);
		$this->assertStringContainsString('Close every brace and bracket you open', $prompt);
	}
}
