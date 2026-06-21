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

	public function testParsesSingleFlight(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'provider' => 'SAS',
				'booking_reference' => 'ABC123',
				'status' => 'confirmed',
				'title' => 'OSL → CPH',
				'segments' => [[
					'start_local' => '2026-07-15T09:30:00',
					'start_timezone' => 'Europe/Oslo',
					'end_local' => '2026-07-15T10:45:00',
					'origin' => 'OSL',
					'destination' => 'CPH',
					'flight_number' => 'SK1234',
				]],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json);

		$this->assertCount(1, $bookings);
		$this->assertSame('flight', $bookings[0]->type);
		$this->assertSame('SAS', $bookings[0]->provider);
		$this->assertSame('ABC123', $bookings[0]->bookingReference);
		$this->assertCount(1, $bookings[0]->segments);
		$this->assertSame('2026-07-15T09:30:00', $bookings[0]->segments[0]->startLocal);
		$this->assertSame('Europe/Oslo', $bookings[0]->segments[0]->startTimezone);
		$this->assertSame('SK1234', $bookings[0]->segments[0]->flightNumber);
	}

	public function testParsesRoundTripAsTwoSegments(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'provider' => 'KLM',
				'booking_reference' => 'RT99',
				'segments' => [
					['start_local' => '2026-08-01T08:00:00', 'origin' => 'AMS', 'destination' => 'JFK'],
					['start_local' => '2026-08-10T18:00:00', 'origin' => 'JFK', 'destination' => 'AMS'],
				],
			]],
		]);

		$bookings = $this->service->parseAndValidate($json);

		$this->assertCount(1, $bookings);
		$this->assertCount(2, $bookings[0]->segments);
	}

	public function testStripsMarkdownFences(): void {
		$raw = "```json\n{\"bookings\": [{\"type\": \"accommodation\", \"segments\": [{\"start_local\": \"2026-09-01\", \"location\": \"Hotel X\"}]}]}\n```";

		$bookings = $this->service->parseAndValidate($raw);

		$this->assertCount(1, $bookings);
		$this->assertSame('accommodation', $bookings[0]->type);
		// A date-only value normalizes to midnight local wall-clock.
		$this->assertSame('2026-09-01T00:00:00', $bookings[0]->segments[0]->startLocal);
	}

	public function testIgnoresProseAroundJson(): void {
		$raw = "Sure! Here is the data you asked for:\n{\"bookings\": []}\nLet me know if you need anything else.";

		$this->assertSame([], $this->service->parseAndValidate($raw));
	}

	public function testDropsUnknownBookingType(): void {
		$json = json_encode([
			'bookings' => [
				['type' => 'event', 'segments' => [['start_local' => '2026-07-15T09:30:00']]],
				['type' => 'flight', 'segments' => [['start_local' => '2026-07-15T09:30:00']]],
			],
		]);

		$bookings = $this->service->parseAndValidate($json);

		$this->assertCount(1, $bookings);
		$this->assertSame('flight', $bookings[0]->type);
	}

	public function testDropsBookingWithoutValidDate(): void {
		// Anti-hallucination: no parseable start time => booking dropped.
		$json = json_encode([
			'bookings' => [[
				'type' => 'flight',
				'segments' => [['start_local' => 'sometime next week', 'origin' => 'OSL']],
			]],
		]);

		$this->assertSame([], $this->service->parseAndValidate($json));
	}

	public function testCancellationStatusPreserved(): void {
		$json = json_encode([
			'bookings' => [[
				'type' => 'car_rental',
				'status' => 'cancelled',
				'segments' => [['start_local' => '2026-07-15T09:30:00', 'location' => 'Hertz OSL']],
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
				'segments' => [['start_local' => '2026-07-15T09:30:00']],
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

	public function testBuildPromptContainsEmailAndSchema(): void {
		$prompt = $this->service->buildPrompt('Your flight is confirmed.', 'Booking confirmation');
		$this->assertStringContainsString('Your flight is confirmed.', $prompt);
		$this->assertStringContainsString('Booking confirmation', $prompt);
		$this->assertStringContainsString('"bookings"', $prompt);
		$this->assertStringContainsString('Do NOT convert timezones', $prompt);
	}
}
