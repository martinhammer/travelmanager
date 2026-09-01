<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Service\Dto\BookingMatch;
use OCA\TravelManager\Service\Dto\ExtractedBooking;
use OCA\TravelManager\Service\Dto\MatchCandidate;

/**
 * Decides whether an incoming extraction is a booking the user already has.
 *
 * **Pure and dependency-free** (no OCP types, no DB) for the same reason
 * ExtractionService is: it encodes the judgement calls, so it is the part that
 * has to be unit-testable standalone — see §7 of CLAUDE.md.
 *
 * The rules it replaces were a single conjunction of
 * `(type, provider, booking_reference)`, which missed real duplicates two ways:
 *
 * - **Identifier roles are not stable across emails.** The same hotel booking
 *   arrived twice, once with `S4RHNGWR` as the reference and `WDP1UANA` as the
 *   confirmation number, and once with `WDP1UANA` as the reference. Neither
 *   email is wrong; senders label the two fields differently. So identifiers
 *   are compared as a **set intersection**, never positionally.
 * - **`provider` is one column for a two-valued fact.** A car hire booked
 *   through Holiday Autos and collected from GOLDCAR is described by both
 *   names, and which one the model puts in `provider` varies by email — while
 *   `details` reliably carries both. So the provider comparison is a set
 *   intersection too, drawn from the header *and* the type-specific details,
 *   and it is never a required term when identifiers already agree.
 *
 * Dates corroborate; they never veto. A change email that moves a booking is
 * still the same booking.
 */
class BookingMatcher {
	/**
	 * Shorter strings are too collision-prone to identify anything: seat
	 * numbers, room counts and "1234" all reach the matcher as strings.
	 */
	private const MIN_IDENTIFIER_LENGTH = 5;

	/**
	 * A short all-digit identifier is real but weak — an 8-digit confirmation
	 * number is effectively unique, a 5-digit one is not. Below this length a
	 * numeric match must be corroborated before it is allowed to suppress a
	 * booking.
	 */
	private const STRONG_NUMERIC_LENGTH = 8;

	/** Placeholders models emit in an identifier field instead of null. */
	private const IDENTIFIER_STOPLIST = [
		'PENDING', 'UNKNOWN', 'CONFIRMED', 'CANCELLED', 'NOTAVAILABLE',
		'NOTAPPLICABLE', 'NOTPROVIDED', 'SEEEMAIL', 'SEEBELOW', 'SEEATTACHED',
		'NOREFERENCE', 'NONUMBER',
	];

	/**
	 * Words that name a category rather than a company, so a name made only of
	 * them identifies nothing. Used to stop "Hotel" alone standing in for
	 * "Hotel Sol" when one name is a prefix of another.
	 */
	private const GENERIC_NAME_WORDS = [
		'hotel', 'hotels', 'hostel', 'apartment', 'apartments', 'aparthotel',
		'resort', 'inn', 'lodge', 'guesthouse', 'bnb', 'rooms', 'suites',
		'airline', 'airlines', 'airways', 'air', 'rent', 'rental', 'rentals',
		'car', 'cars', 'travel', 'holidays', 'booking', 'the',
	];

	/** Legal forms carry no identity: "Goldcar SL" and "GOLDCAR" are one firm. */
	private const COMPANY_SUFFIXES = [
		'ltd', 'limited', 'inc', 'incorporated', 'llc', 'plc', 'gmbh', 'ag',
		'bv', 'nv', 'sa', 'sas', 'srl', 'spa', 'sl', 'as', 'ab', 'oy', 'aps',
		'co', 'corp', 'corporation', 'company', 'group', 'holdings',
	];

	/**
	 * @param list<MatchCandidate> $candidates existing bookings worth comparing
	 *                                         against — see BookingMapper::findMatchCandidates
	 */
	public function match(ExtractedBooking $incoming, array $candidates): ?BookingMatch {
		$incomingIds = $this->identifierSet($incoming->bookingReference, $incoming->confirmationNumber);
		$incomingNames = $this->providerNames($incoming->type, $incoming->provider, $incoming->details);
		$fallback = null;

		foreach ($candidates as $candidate) {
			if ($candidate->type !== $incoming->type) {
				continue;
			}
			$candidateIds = $this->identifierSet($candidate->bookingReference, $candidate->confirmationNumber);
			$candidateNames = $this->providerNames($candidate->type, $candidate->provider, $candidate->details);
			$sharedIds = array_values(array_intersect($incomingIds, $candidateIds));
			$sharedName = $this->sharedName($incomingNames, $candidateNames);
			$sameDay = $this->sameDay($incoming->startDate, $candidate->startDate);

			// Tier 1: an identifier in common. Decisive on its own, without
			// consulting provider or dates — a rebooking keeps its reference and
			// changes everything else.
			if ($sharedIds !== []) {
				if ($this->isStrong($sharedIds) || $sharedName !== null || $sameDay) {
					return new BookingMatch(
						$candidate,
						BookingMatch::REASON_IDENTIFIER,
						true,
						'shared reference ' . $sharedIds[0],
					);
				}
				// A short numeric identifier with nothing else agreeing is as
				// likely a coincidence as a match: store the booking, say so.
				$fallback ??= new BookingMatch(
					$candidate,
					BookingMatch::REASON_POSSIBLE,
					false,
					'the short reference ' . $sharedIds[0] . ' also appears on it, but nothing else matches',
				);
				continue;
			}

			if (!$sameDay || $sharedName === null) {
				continue;
			}

			// Identifiers that disagree are positive evidence of two different
			// bookings, so same-operator-same-day cannot outvote them. Two rooms
			// at one hotel for one night is exactly this case.
			if ($incomingIds !== [] && $candidateIds !== []) {
				$fallback ??= new BookingMatch(
					$candidate,
					BookingMatch::REASON_POSSIBLE,
					false,
					'same ' . $sharedName . ' booking starting the same day, but the references differ ('
						. implode(', ', $incomingIds) . ' vs ' . implode(', ', $candidateIds) . ')',
				);
				continue;
			}

			// Tier 2: no usable identifier on at least one side. The operator and
			// the anchoring day are all there is, so flights need more: two
			// one-way tickets on one airline on one day are a real itinerary.
			if ($incoming->type === 'flight' && !$this->sameFlight($incoming->details, $candidate->details)) {
				continue;
			}

			return new BookingMatch(
				$candidate,
				BookingMatch::REASON_ITINERARY,
				true,
				'same ' . $sharedName . ' booking starting the same day, and neither email carried a reference to tell them apart',
			);
		}

		return $fallback;
	}

	/* ------------------------------------------------------------ identifiers */

	/**
	 * The identifiers a booking is known by, as a set.
	 *
	 * `booking_reference` and `confirmation_number` are deliberately merged: the
	 * two are distinct fields on a booking but the *same kind* of claim about
	 * it, and which one an email uses for a given string is not stable.
	 *
	 * @return list<string>
	 */
	public function identifierSet(?string ...$values): array {
		$out = [];
		foreach ($values as $value) {
			$normalized = $this->normalizeIdentifier($value);
			if ($normalized !== null && !in_array($normalized, $out, true)) {
				$out[] = $normalized;
			}
		}
		return $out;
	}

	/**
	 * Case and punctuation carry no meaning in a reference: "es867 772-590" and
	 * "ES867772590" are one booking. Returns null for anything too short or too
	 * generic to identify a booking at all.
	 */
	public function normalizeIdentifier(?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$stripped = preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
		if (strlen($stripped) < self::MIN_IDENTIFIER_LENGTH) {
			return null;
		}
		if (in_array($stripped, self::IDENTIFIER_STOPLIST, true)) {
			return null;
		}
		return $stripped;
	}

	/**
	 * @param list<string> $identifiers
	 */
	private function isStrong(array $identifiers): bool {
		foreach ($identifiers as $identifier) {
			if (!ctype_digit($identifier) || strlen($identifier) >= self::STRONG_NUMERIC_LENGTH) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------- providers */

	/**
	 * Every company name a booking is associated with, as a set.
	 *
	 * The header `provider` is only one of them, and the least reliable: the
	 * model has to choose between the agency and the operator with nothing in
	 * the email marking which is which. The type-specific fields do mark it, so
	 * they are pulled in alongside and the comparison succeeds whichever way the
	 * choice went.
	 *
	 * @param array<array-key, mixed> $details
	 * @return list<string>
	 */
	public function providerNames(string $type, ?string $provider, array $details): array {
		$raw = [$provider];
		switch ($type) {
			case 'car_rental':
				$raw[] = $this->stringField($details, 'supplier');
				$raw[] = $this->stringField($details, 'rentalCompany');
				break;
			case 'accommodation':
				$raw[] = $this->stringField($details, 'propertyName');
				break;
			case 'flight':
				foreach ($this->segments($details) as $segment) {
					$raw[] = $this->stringField($segment, 'carrier');
					$raw[] = $this->stringField($segment, 'operatingCarrier');
				}
				break;
		}

		$out = [];
		foreach ($raw as $name) {
			$normalized = $this->normalizeName($name);
			if ($normalized !== null && !in_array($normalized, $out, true)) {
				$out[] = $normalized;
			}
		}
		return $out;
	}

	/**
	 * The company name two bookings agree on, or null.
	 *
	 * Exact agreement, or one name being how the other starts: an email may say
	 * "KLM" where the last one said "KLM Royal Dutch Airlines", and a hotel adds
	 * and drops its own descriptors freely. The prefix rule is refused when the
	 * shorter name is nothing but category words, so "Hotel" cannot stand in for
	 * "Hotel Sol" — that is a category, not an identification. Deliberately not
	 * a similarity score: a near-miss belongs in the non-decisive tier, where the
	 * user sees it, rather than behind a threshold nobody can reason about.
	 *
	 * @param list<string> $a
	 * @param list<string> $b
	 */
	private function sharedName(array $a, array $b): ?string {
		foreach ($a as $left) {
			foreach ($b as $right) {
				if ($left === $right) {
					return $left;
				}
				$shorter = strlen($left) <= strlen($right) ? $left : $right;
				$longer = $shorter === $left ? $right : $left;
				if (str_starts_with($longer . ' ', $shorter . ' ') && !$this->isGeneric($shorter)) {
					return $shorter;
				}
			}
		}
		return null;
	}

	private function isGeneric(string $name): bool {
		foreach (explode(' ', $name) as $word) {
			if (!in_array($word, self::GENERIC_NAME_WORDS, true)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lowercase, punctuation to spaces, legal suffixes dropped. Enough to make
	 * "Bo18 Superior ***" and "Bo18 Superior" one property without pretending to
	 * do fuzzy matching — a near-miss belongs in the non-decisive tier, not in a
	 * similarity threshold nobody can reason about.
	 */
	private function normalizeName(?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$spaced = preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '';
		$words = array_values(array_filter(
			explode(' ', trim($spaced)),
			static fn (string $word): bool => $word !== '',
		));
		while ($words !== [] && in_array(end($words), self::COMPANY_SUFFIXES, true)) {
			array_pop($words);
		}
		$joined = implode(' ', $words);
		return $joined === '' ? null : $joined;
	}

	/* ------------------------------------------------------------------ dates */

	/**
	 * Calendar day, never the full timestamp: a confirmation gives a 14:00
	 * check-in and the reminder that follows gives a bare date, which
	 * normalizeDate renders as 00:00. Both are the same stay. Comparing the
	 * stored strings directly keeps this in local wall-clock (V8).
	 */
	private function sameDay(?string $a, ?string $b): bool {
		if ($a === null || $b === null) {
			return false;
		}
		return substr($a, 0, 10) === substr($b, 0, 10);
	}

	/* ----------------------------------------------------------------- flights */

	/**
	 * Same flight number, or failing that the same route. Two separately booked
	 * one-ways on one airline on one day are a common itinerary, so "same
	 * carrier, same day" is not enough to call them one booking.
	 *
	 * @param array<array-key, mixed> $a
	 * @param array<array-key, mixed> $b
	 */
	private function sameFlight(array $a, array $b): bool {
		$numbersA = $this->flightNumbers($a);
		$numbersB = $this->flightNumbers($b);
		if ($numbersA !== [] && $numbersB !== []) {
			return array_intersect($numbersA, $numbersB) !== [];
		}
		return array_intersect($this->routes($a), $this->routes($b)) !== [];
	}

	/**
	 * @param array<array-key, mixed> $details
	 * @return list<string>
	 */
	private function flightNumbers(array $details): array {
		$out = [];
		foreach ($this->segments($details) as $segment) {
			$number = $this->normalizeIdentifierLoose($this->stringField($segment, 'flightNumber'));
			if ($number !== null && !in_array($number, $out, true)) {
				$out[] = $number;
			}
		}
		return $out;
	}

	/**
	 * @param array<array-key, mixed> $details
	 * @return list<string>
	 */
	private function routes(array $details): array {
		$out = [];
		foreach ($this->segments($details) as $segment) {
			$origin = $this->normalizeName($this->stringField($segment, 'origin'));
			$destination = $this->normalizeName($this->stringField($segment, 'destination'));
			if ($origin === null || $destination === null) {
				continue;
			}
			$route = $origin . '>' . $destination;
			if (!in_array($route, $out, true)) {
				$out[] = $route;
			}
		}
		return $out;
	}

	/**
	 * A flight number is short by nature ("EY42"), so it cannot go through the
	 * booking-reference length floor.
	 */
	private function normalizeIdentifierLoose(?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$stripped = preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
		return $stripped === '' ? null : $stripped;
	}

	/* ------------------------------------------------------------------ shared */

	/**
	 * @param array<array-key, mixed> $details
	 * @return list<array<array-key, mixed>>
	 */
	private function segments(array $details): array {
		$raw = $details['segments'] ?? null;
		if (!is_array($raw)) {
			return [];
		}
		return array_values(array_filter($raw, 'is_array'));
	}

	/**
	 * @param array<array-key, mixed> $source
	 */
	private function stringField(array $source, string $key): ?string {
		$value = $source[$key] ?? null;
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		return $value === '' ? null : $value;
	}
}
