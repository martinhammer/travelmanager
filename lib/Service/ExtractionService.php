<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Exception\ExtractionException;
use OCA\TravelManager\Service\Dto\ExtractedBooking;

/**
 * Pure, dependency-free extraction core: builds the LLM prompt and parses /
 * repairs / validates the raw text response into typed bookings.
 *
 * The model classifies each booking and emits a per-type `details` object; this
 * class validates the header, normalises the dates that anchor each type and
 * otherwise passes the details through verbatim, so the JSON shape can be tuned
 * from the prompt without code or schema changes.
 *
 * Kept free of Nextcloud services so it can be unit-tested directly (the LLM and
 * IMAP boundaries are mocked elsewhere). See V7.
 */
class ExtractionService {
	// Canonical booking type strings; mirrored by Booking::TYPE_* constants.
	public const ALLOWED_TYPES = [
		'flight',
		'accommodation',
		'car_rental',
	];

	public const ALLOWED_STATUSES = ['confirmed', 'cancelled', 'changed'];

	private const ACCEPTED_DATE_FORMATS = [
		'Y-m-d\TH:i:s',
		'Y-m-d\TH:i',
		'Y-m-d H:i:s',
		'Y-m-d H:i',
		'Y-m-d',
	];

	/**
	 * Build the instruction prompt for core:text2text. The model must return
	 * ONLY the JSON object described by the schema.
	 */
	public function buildPrompt(string $emailText, ?string $subject = null): string {
		$schema = <<<'JSON'
{
  "bookings": [
    {
      "type": "flight | accommodation | car_rental",
      "provider": "primary operator or brand, e.g. airline / hotel / rental company | null",
      "booking_reference": "string | null",
      "confirmation_number": "string | null",
      "status": "confirmed | cancelled | changed",
      "title": "short human-readable title | null",
      "confidence": 0.0,
      "details": { "... type-specific, see below ..." }
    }
  ]
}

DETAILS when type = "flight":
{
  "passengers": [ { "name": "string", "frequentFlyer": "string | null", "baggage": "string | null" } ],
  "segments": [ {
    "carrier": "marketing airline | null",
    "operatingCarrier": "operating airline | null",
    "flightNumber": "string | null",
    "origin": "airport/city | null",
    "destination": "airport/city | null",
    "departureLocal": "YYYY-MM-DDTHH:MM:SS",
    "departureTimezone": "IANA name | null",
    "arrivalLocal": "YYYY-MM-DDTHH:MM:SS | null",
    "arrivalTimezone": "IANA name | null",
    "cabinClass": "string | null",
    "seat": "string | null",
    "terminal": "string | null",
    "gate": "string | null"
  } ]
}

DETAILS when type = "car_rental":
{
  "supplier": "broker/booking site, e.g. Holiday Autos | null",
  "rentalCompany": "on-site company, e.g. Europcar | null",
  "carType": "e.g. Compact - VW Golf or similar | null",
  "carFeatures": [ "automatic", "air conditioning", "unlimited mileage" ],
  "driver": { "name": "string | null" },
  "pickup":  { "location": "string | null", "local": "YYYY-MM-DDTHH:MM:SS", "timezone": "IANA name | null" },
  "dropoff": { "location": "string | null", "local": "YYYY-MM-DDTHH:MM:SS | null", "timezone": "IANA name | null" }
}

DETAILS when type = "accommodation":
{
  "propertyName": "string | null",
  "address": "string | null",
  "checkIn":  { "local": "YYYY-MM-DDTHH:MM:SS", "timezone": "IANA name | null" },
  "checkOut": { "local": "YYYY-MM-DDTHH:MM:SS | null", "timezone": "IANA name | null" },
  "roomType": "string | null",
  "board": "e.g. room only / breakfast / half board | null",
  "numberOfRooms": 1,
  "guests": [ { "name": "string" } ]
}
JSON;

		$rules = implode("\n", [
			'You extract travel bookings from a single email.',
			'First classify each booking as flight, accommodation, or car_rental, then output ONLY that type\'s details object.',
			'Return ONLY a JSON object matching the schema below. No prose, no markdown fences.',
			'Only include bookings of type flight, accommodation, or car_rental.',
			'booking_reference and confirmation_number are different identifiers; include both when the email shows both.',
			'A round-trip flight has two segments; multi-leg flights have one segment per leg.',
			'Times are LOCAL wall-clock at the relevant place. Do NOT convert timezones.',
			'Use null (or omit) for anything not present in the email. Never invent dates, references or names.',
			'If the email contains no such booking, return {"bookings": []}.',
		]);

		$subjectLine = $subject !== null && $subject !== '' ? "SUBJECT: {$subject}\n\n" : '';

		return $rules . "\n\nSCHEMA:\n" . $schema . "\n\nEMAIL:\n" . $subjectLine . $emailText;
	}

	/**
	 * Parse and validate a raw LLM response into bookings.
	 *
	 * @return ExtractedBooking[]
	 * @throws ExtractionException when the response is not recoverable JSON
	 */
	public function parseAndValidate(string $rawOutput): array {
		$json = $this->extractJsonObject($rawOutput);
		if ($json === null) {
			throw new ExtractionException('No JSON object found in LLM response');
		}

		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			throw new ExtractionException('LLM response is not valid JSON');
		}

		$rawBookings = $decoded['bookings'] ?? null;
		if (!is_array($rawBookings)) {
			throw new ExtractionException('LLM response is missing a "bookings" array');
		}

		$bookings = [];
		foreach ($rawBookings as $rawBooking) {
			if (!is_array($rawBooking)) {
				continue;
			}
			$booking = $this->validateBooking($rawBooking);
			if ($booking !== null) {
				$bookings[] = $booking;
			}
		}
		return $bookings;
	}

	private function validateBooking(array $raw): ?ExtractedBooking {
		// Classify: an unrecognised type falls through the match default to null.
		$type = strtolower($this->nullableString($raw['type'] ?? null) ?? '');
		$rawDetails = $this->asArray($raw['details'] ?? null);
		$validated = match ($type) {
			'flight' => $this->validateFlightDetails($rawDetails),
			'car_rental' => $this->validateCarRentalDetails($rawDetails),
			'accommodation' => $this->validateAccommodationDetails($rawDetails),
			default => null,
		};

		// Anti-hallucination: a booking without its anchoring date(s) is dropped.
		if ($validated === null) {
			return null;
		}
		[$details, $startDate, $endDate] = $validated;

		$status = strtolower($this->nullableString($raw['status'] ?? null) ?? 'confirmed');
		if (!in_array($status, self::ALLOWED_STATUSES, true)) {
			$status = 'confirmed';
		}

		return new ExtractedBooking(
			$type,
			$this->nullableString($raw['provider'] ?? null),
			$this->nullableString($raw['booking_reference'] ?? null),
			$this->nullableString($raw['confirmation_number'] ?? null),
			$status,
			$this->nullableString($raw['title'] ?? null),
			$details,
			$startDate,
			$endDate,
			$this->nullableFloat($raw['confidence'] ?? null),
		);
	}

	/**
	 * @param array<array-key, mixed> $details
	 * @return array{0: array<array-key, mixed>, 1: ?string, 2: ?string}|null
	 */
	private function validateFlightDetails(array $details): ?array {
		$segments = [];
		$dates = [];
		foreach ($this->asArray($details['segments'] ?? null) as $rawSegment) {
			if (!is_array($rawSegment)) {
				continue;
			}
			$departure = $this->normalizeDate($rawSegment['departureLocal'] ?? null);
			if ($departure === null) {
				// A leg without a valid departure time is not usable.
				continue;
			}
			$rawSegment['departureLocal'] = $departure;
			$arrival = $this->normalizeDate($rawSegment['arrivalLocal'] ?? null);
			$rawSegment['arrivalLocal'] = $arrival;
			$segments[] = $rawSegment;
			$dates[] = $departure;
			if ($arrival !== null) {
				$dates[] = $arrival;
			}
		}

		if ($segments === []) {
			return null;
		}

		$details['segments'] = $segments;
		return [$details, $this->minDate($dates), $this->maxDate($dates)];
	}

	/**
	 * @param array<array-key, mixed> $details
	 * @return array{0: array<array-key, mixed>, 1: ?string, 2: ?string}|null
	 */
	private function validateCarRentalDetails(array $details): ?array {
		$pickup = $this->asArray($details['pickup'] ?? null);
		$pickupLocal = $this->normalizeDate($pickup['local'] ?? null);
		if ($pickupLocal === null) {
			return null;
		}
		$pickup['local'] = $pickupLocal;
		$details['pickup'] = $pickup;

		$dropoff = $this->asArray($details['dropoff'] ?? null);
		$dropoffLocal = $this->normalizeDate($dropoff['local'] ?? null);
		$dropoff['local'] = $dropoffLocal;
		$details['dropoff'] = $dropoff;

		return [$details, $pickupLocal, $dropoffLocal ?? $pickupLocal];
	}

	/**
	 * @param array<array-key, mixed> $details
	 * @return array{0: array<array-key, mixed>, 1: ?string, 2: ?string}|null
	 */
	private function validateAccommodationDetails(array $details): ?array {
		$checkIn = $this->asArray($details['checkIn'] ?? null);
		$checkInLocal = $this->normalizeDate($checkIn['local'] ?? null);
		if ($checkInLocal === null) {
			return null;
		}
		$checkIn['local'] = $checkInLocal;
		$details['checkIn'] = $checkIn;

		$checkOut = $this->asArray($details['checkOut'] ?? null);
		$checkOutLocal = $this->normalizeDate($checkOut['local'] ?? null);
		$checkOut['local'] = $checkOutLocal;
		$details['checkOut'] = $checkOut;

		return [$details, $checkInLocal, $checkOutLocal ?? $checkInLocal];
	}

	/**
	 * Strip markdown fences and isolate the first balanced JSON object.
	 */
	public function extractJsonObject(string $text): ?string {
		$text = trim($text);
		// Remove ```json ... ``` or ``` ... ``` fences if present.
		$text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
		$text = preg_replace('/\s*```$/', '', $text) ?? $text;

		$start = strpos($text, '{');
		if ($start === false) {
			return null;
		}

		$depth = 0;
		$inString = false;
		$escaped = false;
		$length = strlen($text);
		for ($i = $start; $i < $length; $i++) {
			$char = $text[$i];
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
			} elseif ($char === '{') {
				$depth++;
			} elseif ($char === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($text, $start, $i - $start + 1);
				}
			}
		}
		return null;
	}

	/**
	 * Normalize a loose date string to local wall-clock 'Y-m-d\TH:i:s', or null.
	 */
	public function normalizeDate(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		foreach (self::ACCEPTED_DATE_FORMATS as $format) {
			$dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);
			$errors = \DateTimeImmutable::getLastErrors();
			if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
				return $dt->format('Y-m-d\TH:i:s');
			}
		}
		return null;
	}

	/**
	 * Smallest of a set of normalized (lexically sortable) date strings, or null.
	 *
	 * @param list<string> $dates
	 */
	private function minDate(array $dates): ?string {
		if ($dates === []) {
			return null;
		}
		return min($dates);
	}

	/**
	 * Largest of a set of normalized (lexically sortable) date strings, or null.
	 *
	 * @param list<string> $dates
	 */
	private function maxDate(array $dates): ?string {
		if ($dates === []) {
			return null;
		}
		return max($dates);
	}

	private function nullableString(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		return $value === '' ? null : $value;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function asArray(mixed $value): array {
		return is_array($value) ? $value : [];
	}

	private function nullableFloat(mixed $value): ?float {
		if (is_int($value) || is_float($value)) {
			return (float)$value;
		}
		if (is_string($value) && is_numeric($value)) {
			return (float)$value;
		}
		return null;
	}
}
