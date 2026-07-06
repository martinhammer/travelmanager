<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Exception\ExtractionException;
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

		$bookings = $this->service->parseAndValidate($json);

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

		$bookings = $this->service->parseAndValidate($json);

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

		$this->assertSame([], $this->service->parseAndValidate($json));
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

		$bookings = $this->service->parseAndValidate($json);

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
				'details' => ['rentalCompany' => 'Hertz', 'pickup' => ['location' => 'OSL']],
			]],
		]);

		$this->assertSame([], $this->service->parseAndValidate($json));
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

		$bookings = $this->service->parseAndValidate($json);

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

		$this->assertSame([], $this->service->parseAndValidate($json));
	}

	public function testAcceptsDateOnlyCheckIn(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'accommodation',
				'details' => ['checkIn' => ['local' => '2026-09-01'], 'checkOut' => ['local' => '2026-09-03']],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json);

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

		$bookings = $this->service->parseAndValidate($json);

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

		$bookings = $this->service->parseAndValidate($json);

		$this->assertCount(1, $bookings);
		$this->assertSame('flight', $bookings[0]->type);
	}

	public function testStripsMarkdownFences(): void {
		$raw = "```json\n{\"bookings\": [{\"type\": \"accommodation\", \"details\": {\"checkIn\": {\"local\": \"2026-09-01\"}}}]}\n```";

		$bookings = $this->service->parseAndValidate($raw);

		$this->assertCount(1, $bookings);
		$this->assertSame('accommodation', $bookings[0]->type);
	}

	public function testIgnoresProseAroundJson(): void {
		$raw = "Sure! Here is the data you asked for:\n{\"bookings\": []}\nLet me know if you need anything else.";

		$this->assertSame([], $this->service->parseAndValidate($raw));
	}

	public function testCancellationStatusPreserved(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'car_rental',
				'status' => 'cancelled',
				'details' => ['pickup' => ['local' => '2026-07-15T09:30:00']],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json);

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

		$bookings = $this->service->parseAndValidate($json);

		$this->assertSame('confirmed', $bookings[0]->status);
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
		$this->assertSame(['flight', 'accommodation', 'car_rental'], ExtractionService::ALLOWED_TYPES);
	}

	public function testBuildPromptContainsEmailAndSchema(): void {
		$prompt = $this->service->buildPrompt('Your flight is confirmed.', 'Booking confirmation');
		$this->assertStringContainsString('Your flight is confirmed.', $prompt);
		$this->assertStringContainsString('Booking confirmation', $prompt);
		$this->assertStringContainsString('"bookings"', $prompt);
		$this->assertStringContainsString('confirmation_number', $prompt);
		$this->assertStringContainsString('Do NOT convert timezones', $prompt);
	}
}
