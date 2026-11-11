<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * What happened to one PDF attachment of an email: stored, as a JSON list, in
 * `messages.attachments`.
 *
 * Fixed at ingestion and true for every extraction attempt after it, which is
 * why it is not an `issue_reasons` slug — those describe the last attempt and
 * are cleared on retry.
 *
 * @psalm-type AttachmentRecordArray = array{filename: ?string, pages: ?int, pagesRead: ?int, chars: ?int, outcome: string, truncated: bool}
 */
final class AttachmentRecord {
	/** Text extracted. */
	public const OUTCOME_READ = 'read';
	/** No text layer — probably a scan. */
	public const OUTCOME_NO_TEXT = 'no_text';
	/** Needs a password, or the library could not decrypt it. */
	public const OUTCOME_ENCRYPTED = 'encrypted';
	/** Not a readable PDF, or downloading it failed. */
	public const OUTCOME_FAILED = 'failed';
	/** A previous attempt died while reading it (out of memory or time). */
	public const OUTCOME_CRASHED = 'crashed';
	public const OUTCOME_TOO_LARGE = 'too_large';
	public const OUTCOME_TOO_MANY = 'too_many';
	/** The admin turned attachment reading off. */
	public const OUTCOME_DISABLED = 'disabled';

	public const OUTCOMES = [
		self::OUTCOME_READ,
		self::OUTCOME_NO_TEXT,
		self::OUTCOME_ENCRYPTED,
		self::OUTCOME_FAILED,
		self::OUTCOME_CRASHED,
		self::OUTCOME_TOO_LARGE,
		self::OUTCOME_TOO_MANY,
		self::OUTCOME_DISABLED,
	];

	public function __construct(
		public readonly ?string $filename,
		public readonly string $outcome,
		/** Null when the file was never opened. */
		public readonly ?int $pages = null,
		public readonly ?int $pagesRead = null,
		/** Bytes of text sent to the model, after any cut. */
		public readonly ?int $chars = null,
		public readonly bool $truncated = false,
	) {
	}

	/** @return AttachmentRecordArray */
	public function toArray(): array {
		return [
			'filename' => $this->filename,
			'pages' => $this->pages,
			'pagesRead' => $this->pagesRead,
			'chars' => $this->chars,
			'outcome' => $this->outcome,
			'truncated' => $this->truncated,
		];
	}

	/**
	 * The column value, or null when the email had no PDF — so the column is
	 * empty exactly where there is nothing to say.
	 *
	 * @param list<self> $records
	 */
	public static function encodeList(array $records): ?string {
		if ($records === []) {
			return null;
		}
		return json_encode(array_map(static fn (self $r): array => $r->toArray(), $records), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * The column value read back. Anything unexpected is dropped rather than
	 * failing the row: this is a display aid, and a whole message list must not
	 * 500 over one malformed entry.
	 *
	 * @return list<AttachmentRecordArray>
	 */
	public static function decodeList(?string $json): array {
		if ($json === null || $json === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		$records = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$outcome = $entry['outcome'] ?? null;
			if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
				continue;
			}
			$records[] = [
				'filename' => self::stringOrNull($entry['filename'] ?? null),
				'pages' => self::intOrNull($entry['pages'] ?? null),
				'pagesRead' => self::intOrNull($entry['pagesRead'] ?? null),
				'chars' => self::intOrNull($entry['chars'] ?? null),
				'outcome' => $outcome,
				'truncated' => ($entry['truncated'] ?? false) === true,
			];
		}
		return $records;
	}

	private static function stringOrNull(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	private static function intOrNull(mixed $value): ?int {
		return is_int($value) ? $value : null;
	}
}
