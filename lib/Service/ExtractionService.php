<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Exception\ExtractionException;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCA\TravelManager\Service\Dto\ExtractedSegment;

/**
 * Pure, dependency-free extraction core: builds the LLM prompt and parses /
 * repairs / validates the raw text response into typed bookings.
 *
 * Kept free of Nextcloud services so it can be unit-tested directly
 * (the LLM and IMAP boundaries are mocked elsewhere). See V7.
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
      "provider": "string",
      "booking_reference": "string | null",
      "status": "confirmed | cancelled | changed",
      "title": "string",
      "confidence": 0.0,
      "segments": [
        {
          "start_local": "YYYY-MM-DDTHH:MM:SS",
          "start_timezone": "IANA name | null",
          "end_local": "YYYY-MM-DDTHH:MM:SS | null",
          "end_timezone": "IANA name | null",
          "origin": "string | null",
          "destination": "string | null",
          "location": "string | null",
          "flight_number": "string | null",
          "carrier": "string | null",
          "seat": "string | null",
          "terminal": "string | null",
          "gate": "string | null",
          "extra": {},
          "confidence": 0.0
        }
      ]
    }
  ]
}
JSON;

		$rules = implode("\n", [
			'You extract travel bookings from a single email.',
			'Return ONLY a JSON object matching the schema below. No prose, no markdown fences.',
			'Only include bookings of type flight, accommodation, or car_rental.',
			'A round-trip flight has two segments; multi-leg flights have one segment per leg.',
			'Times are LOCAL wall-clock at the relevant place. Do NOT convert timezones.',
			'Use null for anything not present in the email. Never invent dates or references.',
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
		$type = strtolower($this->nullableString($raw['type'] ?? null) ?? '');
		if (!in_array($type, self::ALLOWED_TYPES, true)) {
			return null;
		}

		$segments = [];
		foreach ($this->asArray($raw['segments'] ?? null) as $rawSegment) {
			if (!is_array($rawSegment)) {
				continue;
			}
			$segment = $this->validateSegment($rawSegment);
			if ($segment !== null) {
				$segments[] = $segment;
			}
		}

		// Anti-hallucination: a booking with no dated segment is dropped.
		if ($segments === []) {
			return null;
		}

		$status = strtolower($this->nullableString($raw['status'] ?? null) ?? 'confirmed');
		if (!in_array($status, self::ALLOWED_STATUSES, true)) {
			$status = 'confirmed';
		}

		return new ExtractedBooking(
			$type,
			$this->nullableString($raw['provider'] ?? null),
			$this->nullableString($raw['booking_reference'] ?? null),
			$status,
			$this->nullableString($raw['title'] ?? null),
			$segments,
			$this->nullableFloat($raw['confidence'] ?? null),
		);
	}

	private function validateSegment(array $raw): ?ExtractedSegment {
		$start = $this->normalizeDate($raw['start_local'] ?? null);
		if ($start === null) {
			// A segment without a valid start time is not usable.
			return null;
		}

		return new ExtractedSegment(
			$start,
			$this->nullableString($raw['start_timezone'] ?? null),
			$this->normalizeDate($raw['end_local'] ?? null),
			$this->nullableString($raw['end_timezone'] ?? null),
			$this->nullableString($raw['origin'] ?? null),
			$this->nullableString($raw['destination'] ?? null),
			$this->nullableString($raw['location'] ?? null),
			$this->nullableString($raw['flight_number'] ?? null),
			$this->nullableString($raw['carrier'] ?? null),
			$this->nullableString($raw['seat'] ?? null),
			$this->nullableString($raw['terminal'] ?? null),
			$this->nullableString($raw['gate'] ?? null),
			$this->asArray($raw['extra'] ?? null),
			$this->nullableFloat($raw['confidence'] ?? null),
		);
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
