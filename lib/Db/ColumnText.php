<?php

declare(strict_types=1);

namespace OCA\TravelManager\Db;

/**
 * Text made safe for the database, for values the app did not write itself:
 * an email header, a model's answer, the text of a PDF.
 *
 * Each rule is something a database refuses outright, and a refusal used to
 * cost more than the value: an insert that throws inside the mailbox read is
 * retried on every run and never stored. MySQL in strict mode refuses a value
 * longer than its `VARCHAR`; PostgreSQL refuses a NUL byte in any text; both
 * refuse invalid UTF-8. SQLite, which the test suite runs on, refuses none of
 * them, so the suite cannot see this happen.
 *
 * Pure and dependency-free. For a value the *user* typed, refuse instead of
 * cutting (see `fits`): silently shortening what someone just entered is a
 * surprise, while cutting an email's subject is not.
 */
final class ColumnText {
	/** The width of every `VARCHAR` the app's migrations declare for text. */
	public const VARCHAR = 255;

	/** Valid UTF-8 with no NUL bytes; for `TEXT` columns, which have no width. */
	public static function clean(string $value): string {
		return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
	}

	/**
	 * Clean, then cut to the column's width, counted in characters as both MySQL
	 * (utf8mb4) and PostgreSQL count a `VARCHAR`.
	 */
	public static function fit(?string $value, int $length = self::VARCHAR): ?string {
		if ($value === null) {
			return null;
		}
		$value = self::clean($value);
		return mb_strlen($value, 'UTF-8') > $length ? mb_substr($value, 0, $length, 'UTF-8') : $value;
	}

	/** Whether a value fits the column as it is, for refusing what a user typed. */
	public static function fits(?string $value, int $length = self::VARCHAR): bool {
		return $value === null || mb_strlen($value, 'UTF-8') <= $length;
	}
}
