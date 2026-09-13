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
		$repairNote = null;
		$json = $this->service->extractJsonObject('{"bookings": [{"type": "flight"]}', $repairNote);

		$this->assertSame('{"bookings": [{"type": "flight"}]}', $json);
		$this->assertNotNull($repairNote);
	}

	public function testClosesNestedContainersInTheRightOrder(): void {
		$repairNote = null;
		// Needs "}]}" — a plain depth counter would emit "}}}".
		$json = $this->service->extractJsonObject('{"bookings": [{"type": "flight"', $repairNote);

		$this->assertSame('{"bookings": [{"type": "flight"}]}', $json);
		$this->assertNotNull($repairNote);
	}

	public function testReportsNoRepairForWellFormedResponses(): void {
		$repairNote = null;
		$this->service->extractJsonObject('{"bookings": []}', $repairNote);
		$this->assertNull($repairNote);
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
