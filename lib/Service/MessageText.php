<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

/**
 * Assembles what is sent to the model for one email: its body, then the text of
 * each PDF attachment under a heading. The result is stored as `body_text`,
 * which stays "exactly what the model was given" — so Retry, the Raw message
 * pane and backups need no notion of attachments.
 *
 * Pure: every limit is applied here and tested here.
 */
final class MessageText {
	/** The body's cap when the email has no attachment text — unchanged from before attachments. */
	public const BODY_ALONE = 20000;
	/** Body plus attachment sections, together. */
	public const TOTAL = 32000;
	/** One attachment's text, before its heading. Real tickets run 1–4 KB. */
	public const PER_ATTACHMENT = 8000;

	public const TRUNCATED = '[… truncated]';

	/**
	 * Cut $text to at most $limit bytes, marked so the model and the Raw message
	 * pane both see that something is missing.
	 *
	 * @return array{0: string, 1: bool} the text, and whether it was cut
	 */
	public static function truncate(string $text, int $limit): array {
		if (strlen($text) <= $limit) {
			return [$text, false];
		}
		$marker = "\n" . self::TRUNCATED;
		// mb_strcut, not substr: a cut through a multibyte character would leave
		// invalid UTF-8 for the database and the prompt.
		return [rtrim(mb_strcut($text, 0, max(0, $limit - strlen($marker)), 'UTF-8')) . $marker, true];
	}

	/**
	 * One attachment's heading and text. The model is told what the heading
	 * means in the prompt; the page counts tell it (and whoever reads the Raw
	 * message pane) when only the start of a document was read.
	 */
	public static function section(?string $filename, int $index, int $pages, int $pagesRead, string $text): string {
		$name = $filename ?? 'attachment ' . $index;
		$extent = $pagesRead < $pages
			? 'first ' . $pagesRead . ' of ' . $pages . ' pages'
			: $pages . ($pages === 1 ? ' page' : ' pages');
		return '--- ATTACHMENT: ' . $name . ' (PDF, ' . $extent . ") ---\n" . $text;
	}

	/**
	 * The body first, then the sections in order. Attachments are capped first
	 * (they carry the booking whenever they exist at all), and the body takes
	 * what is left of the total — never more than it would get alone.
	 *
	 * @param list<string> $sections from section(), each already within PER_ATTACHMENT
	 */
	public static function assemble(string $body, array $sections): string {
		$attachments = implode("\n\n", $sections);
		$bodyLimit = $sections === []
			? self::BODY_ALONE
			: min(self::BODY_ALONE, self::TOTAL - strlen($attachments) - 2);
		[$body] = self::truncate(trim($body), max(0, $bodyLimit));

		if ($attachments === '') {
			return $body;
		}
		return $body === '' ? $attachments : $body . "\n\n" . $attachments;
	}
}
