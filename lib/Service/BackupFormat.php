<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Exception\InvalidBackupException;
use OCA\TravelManager\Service\Dto\BackupBooking;
use OCA\TravelManager\Service\Dto\BackupContents;
use OCA\TravelManager\Service\Dto\BackupMessage;
use OCA\TravelManager\Service\Dto\BackupTrip;

/**
 * Decides whether a file is a backup we can restore, and turns it into typed rows.
 *
 * Everything is checked before anything is written, because the caller may be
 * about to delete the user's data on the strength of the answer. The checks are
 * the ones the database or the UI would otherwise trip over later: allowlisted
 * states, column widths, dates in the one form we write, references that resolve.
 *
 * Pure and free of OCP types (CLAUDE.md §7), hence literal state strings rather
 * than the entities' constants.
 */
class BackupFormat {
	public const FORMAT = 'travelmanager-backup';
	/** Bump on any change an older build could not read; older files stay readable. */
	public const VERSION = 1;
	/** Every date in the file: stored wall-clock, no offset (V8). */
	public const DATE_FORMAT = 'Y-m-d\TH:i:s';

	private const BOOKING_STATUSES = ['active', 'cancelled', 'superseded'];
	private const REVIEW_STATES = ['draft', 'confirmed', 'discarded', 'archived'];
	private const MESSAGE_STATUSES = ['queued', 'processing', 'processed', 'failed', 'no_booking', 'dropped', 'related'];
	private const FAILURE_KINDS = ['schedule', 'provider', 'validation', 'no_text'];
	private const TRIP_TYPES = ['work', 'leisure'];

	/** @throws InvalidBackupException */
	public function parse(string $json): BackupContents {
		try {
			$root = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new InvalidBackupException('This file is not valid JSON: ' . $e->getMessage());
		}
		if (!is_array($root) || ($root['format'] ?? null) !== self::FORMAT) {
			throw new InvalidBackupException('This file is not a Travel Manager backup.');
		}
		$version = $root['formatVersion'] ?? null;
		if (!is_int($version) || $version < 1) {
			throw new InvalidBackupException('This backup does not say which format version it uses.');
		}
		if ($version > self::VERSION) {
			throw new InvalidBackupException('This backup was made by a newer version of Travel Manager. Update the app, then import it again.');
		}

		$trips = [];
		$tripIds = [];
		foreach ($this->rows($root, 'trips') as $index => $row) {
			$trip = $this->trip($row, 'trips[' . $index . ']');
			if (isset($tripIds[$trip->id])) {
				throw new InvalidBackupException('trips[' . $index . '].id: used by more than one trip.');
			}
			$tripIds[$trip->id] = true;
			$trips[] = $trip;
		}

		$bookings = [];
		$bookingIds = [];
		foreach ($this->rows($root, 'bookings') as $index => $row) {
			$booking = $this->booking($row, 'bookings[' . $index . ']');
			if (isset($bookingIds[$booking->id])) {
				throw new InvalidBackupException('bookings[' . $index . '].id: used by more than one booking.');
			}
			if ($booking->tripId !== null && !isset($tripIds[$booking->tripId])) {
				throw new InvalidBackupException('bookings[' . $index . '].tripId: no such trip in this file.');
			}
			$bookingIds[$booking->id] = true;
			$bookings[] = $booking;
		}

		$messages = [];
		$messageIds = [];
		foreach ($this->rows($root, 'messages') as $index => $row) {
			$message = $this->message($row, 'messages[' . $index . ']');
			if (isset($messageIds[$message->messageId])) {
				throw new InvalidBackupException('messages[' . $index . '].messageId: used by more than one message.');
			}
			$messageIds[$message->messageId] = true;
			$messages[] = $message;
		}

		// Ids carry no meaning across servers, but the order they imply does: the
		// queue releases by row id, and a duplicate group is named after its oldest.
		usort($bookings, static fn (BackupBooking $a, BackupBooking $b): int => $a->id <=> $b->id);

		return new BackupContents(
			$this->string($root, 'appVersion', 'appVersion', 32),
			$this->date($root, 'exportedAt', 'exportedAt') ?? throw new InvalidBackupException('exportedAt: missing.'),
			$this->bool($root, 'includesEmailText', 'includesEmailText'),
			$trips,
			$bookings,
			$messages,
		);
	}

	/**
	 * @param array<array-key, mixed> $root
	 * @return list<array<array-key, mixed>>
	 */
	private function rows(array $root, string $key): array {
		$rows = $root[$key] ?? null;
		if (!is_array($rows) || !array_is_list($rows)) {
			throw new InvalidBackupException($key . ': expected a list.');
		}
		$checked = [];
		foreach ($rows as $index => $row) {
			if (!is_array($row)) {
				throw new InvalidBackupException($key . '[' . $index . ']: expected an object.');
			}
			$checked[] = $row;
		}
		return $checked;
	}

	/** @param array<array-key, mixed> $row */
	private function trip(array $row, string $at): BackupTrip {
		$name = $this->string($row, 'name', $at, 255);
		if (trim($name) === '') {
			throw new InvalidBackupException($at . '.name: must not be empty.');
		}
		$color = $this->optionalString($row, 'color', $at, 7);
		if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new InvalidBackupException($at . '.color: expected #rrggbb.');
		}
		return new BackupTrip(
			$this->int($row, 'id', $at),
			$name,
			$this->optionalChoice($row, 'type', $at, self::TRIP_TYPES),
			$color,
			$this->date($row, 'startDate', $at),
			$this->date($row, 'endDate', $at),
			$this->optionalString($row, 'notes', $at),
			$this->date($row, 'createdAt', $at),
			$this->date($row, 'updatedAt', $at),
		);
	}

	/** @param array<array-key, mixed> $row */
	private function booking(array $row, string $at): BackupBooking {
		$details = $row['details'] ?? null;
		if ($details !== null && !is_array($details)) {
			throw new InvalidBackupException($at . '.details: expected an object.');
		}
		$confidence = $row['confidence'] ?? null;
		if ($confidence !== null && !is_int($confidence) && !is_float($confidence)) {
			throw new InvalidBackupException($at . '.confidence: expected a number.');
		}
		return new BackupBooking(
			$this->int($row, 'id', $at),
			$this->optionalInt($row, 'tripId', $at),
			$this->choice($row, 'type', $at, ExtractionService::ALLOWED_TYPES),
			$this->optionalString($row, 'provider', $at, 255),
			$this->optionalString($row, 'bookingReference', $at, 255),
			$this->optionalString($row, 'confirmationNumber', $at, 255),
			$this->optionalString($row, 'title', $at, 255),
			$this->choice($row, 'status', $at, self::BOOKING_STATUSES),
			$this->choice($row, 'reviewState', $at, self::REVIEW_STATES),
			$this->optionalString($row, 'sourceMessageId', $at, 255),
			$this->optionalInt($row, 'duplicateGroupId', $at),
			$confidence === null ? null : (float)$confidence,
			$details,
			$this->date($row, 'startDate', $at),
			$this->date($row, 'endDate', $at),
			$this->date($row, 'createdAt', $at),
			$this->date($row, 'updatedAt', $at),
			$this->date($row, 'confirmedAt', $at),
		);
	}

	/** @param array<array-key, mixed> $row */
	private function message(array $row, string $at): BackupMessage {
		$messageId = $this->string($row, 'messageId', $at, 255);
		if ($messageId === '') {
			throw new InvalidBackupException($at . '.messageId: must not be empty.');
		}
		$attempts = $this->int($row, 'attempts', $at);
		if ($attempts < 0) {
			throw new InvalidBackupException($at . '.attempts: must not be negative.');
		}
		return new BackupMessage(
			$this->string($row, 'mailbox', $at, 255),
			$messageId,
			$this->optionalInt($row, 'uidValidity', $at),
			$this->optionalInt($row, 'imapUid', $at),
			$this->choice($row, 'status', $at, self::MESSAGE_STATUSES),
			$this->optionalString($row, 'subject', $at, 255),
			$this->optionalString($row, 'sender', $at, 255),
			$this->date($row, 'sentAt', $at),
			$this->optionalString($row, 'bodyText', $at),
			$attempts,
			$this->optionalChoice($row, 'failureKind', $at, self::FAILURE_KINDS),
			$this->stringList($row, 'issueReasons', $at),
			$this->intList($row, 'relatedBookingIds', $at),
			$this->optionalString($row, 'error', $at),
			$this->optionalString($row, 'lastResponse', $at),
			$this->date($row, 'processedAt', $at),
			$this->bool($row, 'discarded', $at),
		);
	}

	/** @param array<array-key, mixed> $row */
	private function string(array $row, string $key, string $at, ?int $maxLength = null): string {
		return $this->optionalString($row, $key, $at, $maxLength)
			?? throw new InvalidBackupException($this->field($key, $at) . ': missing.');
	}

	/** @param array<array-key, mixed> $row */
	private function optionalString(array $row, string $key, string $at, ?int $maxLength = null): ?string {
		$value = $row[$key] ?? null;
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidBackupException($this->field($key, $at) . ': expected text.');
		}
		if ($maxLength !== null && mb_strlen($value) > $maxLength) {
			throw new InvalidBackupException($this->field($key, $at) . ': longer than ' . $maxLength . ' characters.');
		}
		return $value;
	}

	/** @param array<array-key, mixed> $row */
	private function int(array $row, string $key, string $at): int {
		return $this->optionalInt($row, $key, $at)
			?? throw new InvalidBackupException($this->field($key, $at) . ': missing.');
	}

	/** @param array<array-key, mixed> $row */
	private function optionalInt(array $row, string $key, string $at): ?int {
		$value = $row[$key] ?? null;
		if ($value !== null && !is_int($value)) {
			throw new InvalidBackupException($this->field($key, $at) . ': expected a whole number.');
		}
		return $value;
	}

	/** @param array<array-key, mixed> $row */
	private function bool(array $row, string $key, string $at): bool {
		$value = $row[$key] ?? null;
		if (!is_bool($value)) {
			throw new InvalidBackupException($this->field($key, $at) . ': expected true or false.');
		}
		return $value;
	}

	/**
	 * @param array<array-key, mixed> $row
	 * @param list<string> $allowed
	 */
	private function choice(array $row, string $key, string $at, array $allowed): string {
		return $this->optionalChoice($row, $key, $at, $allowed)
			?? throw new InvalidBackupException($this->field($key, $at) . ': missing.');
	}

	/**
	 * @param array<array-key, mixed> $row
	 * @param list<string> $allowed
	 */
	private function optionalChoice(array $row, string $key, string $at, array $allowed): ?string {
		$value = $this->optionalString($row, $key, $at);
		if ($value !== null && !in_array($value, $allowed, true)) {
			throw new InvalidBackupException($this->field($key, $at) . ': not one of ' . implode(', ', $allowed) . '.');
		}
		return $value;
	}

	/**
	 * Strict round trip, because createFromFormat quietly rolls "02-31" over into
	 * March and we would rather refuse a date than move someone's flight.
	 *
	 * @param array<array-key, mixed> $row
	 */
	private function date(array $row, string $key, string $at): ?string {
		$value = $this->optionalString($row, $key, $at);
		if ($value === null) {
			return null;
		}
		$parsed = \DateTime::createFromFormat('!' . self::DATE_FORMAT, $value);
		if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $value) {
			throw new InvalidBackupException($this->field($key, $at) . ': expected a date as YYYY-MM-DDThh:mm:ss.');
		}
		return $value;
	}

	/**
	 * @param array<array-key, mixed> $row
	 * @return list<string>
	 */
	private function stringList(array $row, string $key, string $at): array {
		$strings = [];
		foreach ($this->list($row, $key, $at) as $value) {
			if (!is_string($value)) {
				throw new InvalidBackupException($this->field($key, $at) . ': expected a list of text.');
			}
			$strings[] = $value;
		}
		return $strings;
	}

	/**
	 * @param array<array-key, mixed> $row
	 * @return list<int>
	 */
	private function intList(array $row, string $key, string $at): array {
		$ints = [];
		foreach ($this->list($row, $key, $at) as $value) {
			if (!is_int($value)) {
				throw new InvalidBackupException($this->field($key, $at) . ': expected a list of whole numbers.');
			}
			$ints[] = $value;
		}
		return $ints;
	}

	/**
	 * @param array<array-key, mixed> $row
	 * @return list<mixed>
	 */
	private function list(array $row, string $key, string $at): array {
		$value = $row[$key] ?? [];
		if (!is_array($value) || !array_is_list($value)) {
			throw new InvalidBackupException($this->field($key, $at) . ': expected a list.');
		}
		return $value;
	}

	/** A root-level field is its own position; a row's field hangs off the row. */
	private function field(string $key, string $at): string {
		return $key === $at ? $key : $at . '.' . $key;
	}
}
