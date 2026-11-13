<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\ColumnText;
use OCA\TravelManager\Exception\InvalidBackupException;
use OCA\TravelManager\Service\Dto\AttachmentRecord;
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

	// Limits on what a file can make us store, or send to the model. A file is
	// text anyone can edit, and every row lands under the importing user's id,
	// so nothing in it may be larger than what the app would have written
	// itself. An export never reaches them; past them, a long text is cut as
	// live writes cut it, and anything else refuses the file.

	/** Rows in one list. Far beyond one person's travel mail, and it bounds the queue. */
	private const MAX_ROWS = 50000;
	/** `error`, `lastResponse` and trip notes, as the model's response is stored. */
	private const LONG_TEXT = 20000;
	/** A booking's details are read from a response of at most LONG_TEXT characters. */
	private const DETAILS_BYTES = 100000;
	/** `attempts` is an INTEGER column. */
	private const INT_MAX = 2147483647;

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
		if (count($rows) > self::MAX_ROWS) {
			throw new InvalidBackupException($key . ': more than ' . self::MAX_ROWS . ' rows.');
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
			$this->longText($row, 'notes', $at),
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
		if ($details !== null && strlen(json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::DETAILS_BYTES) {
			throw new InvalidBackupException($at . '.details: larger than ' . self::DETAILS_BYTES . ' bytes.');
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
		if ($attempts < 0 || $attempts > self::INT_MAX) {
			throw new InvalidBackupException($at . '.attempts: must be between 0 and ' . self::INT_MAX . '.');
		}
		$status = $this->choice($row, 'status', $at, self::MESSAGE_STATUSES);
		$body = $this->optionalString($row, 'bodyText', $at);
		return new BackupMessage(
			$this->string($row, 'mailbox', $at, 255),
			$messageId,
			$this->optionalInt($row, 'uidValidity', $at),
			$this->optionalInt($row, 'imapUid', $at),
			$status,
			$this->optionalString($row, 'subject', $at, 255),
			$this->optionalString($row, 'sender', $at, 255),
			$this->date($row, 'sentAt', $at),
			$body === null ? null : $this->bodyText($body, in_array($status, ['queued', 'processing'], true)),
			$attempts,
			$this->optionalChoice($row, 'failureKind', $at, self::FAILURE_KINDS),
			$this->stringList($row, 'issueReasons', $at),
			$this->intList($row, 'relatedBookingIds', $at),
			$this->longText($row, 'error', $at),
			$this->longText($row, 'lastResponse', $at),
			$this->date($row, 'processedAt', $at),
			$this->bool($row, 'discarded', $at),
			$this->attachments($row, $at),
		);
	}

	/**
	 * An email's text, no longer than ingestion would have made it.
	 *
	 * A row waiting for the model is sent on the next tick, so it gets exactly
	 * the cap a freshly read email gets (MessageText::TOTAL bytes). A finished
	 * one is only sent if someone retries it, and may predate that cap: until
	 * 1.19.0 the body was cut at 20 000 *characters*, which in a non-Latin script
	 * is more bytes than TOTAL. So it is held to TOTAL characters, which no export
	 * exceeds.
	 */
	private function bodyText(string $body, bool $unfinished): string {
		if ($unfinished) {
			return MessageText::truncate($body, MessageText::TOTAL)[0];
		}
		if (mb_strlen($body) <= MessageText::TOTAL) {
			return $body;
		}
		return rtrim(mb_substr($body, 0, MessageText::TOTAL)) . "\n" . MessageText::TRUNCATED;
	}

	/**
	 * Free text cut to LONG_TEXT characters rather than refused: a long note or
	 * error is no reason to lose the rest of someone's backup.
	 *
	 * @param array<array-key, mixed> $row
	 */
	private function longText(array $row, string $key, string $at): ?string {
		$value = $this->optionalString($row, $key, $at);
		return $value === null || mb_strlen($value) <= self::LONG_TEXT ? $value : mb_substr($value, 0, self::LONG_TEXT);
	}

	/**
	 * Optional, since files from before 1.19.0 have none. Entries that do not
	 * make sense are dropped rather than refusing the file, as when the column
	 * is read: it records what happened, and nothing depends on it.
	 *
	 * @param array<array-key, mixed> $row
	 * @return list<array{filename: ?string, pages: ?int, pagesRead: ?int, chars: ?int, outcome: string, truncated: bool}>
	 */
	private function attachments(array $row, string $at): array {
		$value = $row['attachments'] ?? null;
		if ($value === null) {
			return [];
		}
		if (!is_array($value)) {
			throw new InvalidBackupException($this->field('attachments', $at) . ': expected a list.');
		}
		return AttachmentRecord::decodeList(json_encode($value, JSON_THROW_ON_ERROR));
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
		// `\u0000` is valid JSON and a NUL that PostgreSQL refuses mid-import.
		$value = ColumnText::clean($value);
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
		$this->fitsJoined($strings, $key, $at);
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
		$this->fitsJoined($ints, $key, $at);
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

	/**
	 * Lists are stored comma-joined in a VARCHAR, so it is the joined form that
	 * has to fit. An export always does, since that is where it was read from.
	 *
	 * @param list<string|int> $values
	 */
	private function fitsJoined(array $values, string $key, string $at): void {
		if (mb_strlen(implode(',', $values)) > ColumnText::VARCHAR) {
			throw new InvalidBackupException($this->field($key, $at) . ': longer than ' . ColumnText::VARCHAR . ' characters once joined.');
		}
	}

	/** A root-level field is its own position; a row's field hangs off the row. */
	private function field(string $key, string $at): string {
		return $key === $at ? $key : $at . '.' . $key;
	}
}
