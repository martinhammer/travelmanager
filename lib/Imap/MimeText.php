<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use Horde_Mime_Part;

/**
 * Decides which parts of a message make up the text handed to the model, and
 * assembles it. Pure over a Horde_Mime_Part tree: the caller supplies each
 * part's decoded contents, so this is testable on parsed raw MIME without IMAP.
 *
 * Horde's own `findBody()` returns the *first* inline text part and nothing
 * else, which silently loses the booking in two ordinary shapes of mail:
 * - a forward whose own note is empty and whose original rides along as an
 *   attached `message/rfc822` — the first text part is the empty note;
 * - a `multipart/alternative` whose plain half is empty while the HTML half
 *   carries everything.
 * So every inline text part counts, an attached message is read as content (it
 * is the email being forwarded, not a file), and within an alternative the
 * plain half wins only when it actually says something.
 */
final class MimeText {
	/**
	 * Every part the text could be built from, in the order to fetch them.
	 *
	 * @return list<string> MIME ids
	 */
	public static function partIds(Horde_Mime_Part $root): array {
		$root->buildMimeIds();
		$ids = [];
		foreach (self::slots($root) as $candidates) {
			foreach ($candidates as $candidate) {
				$ids[] = $candidate['part']->getMimeId();
			}
		}
		return $ids;
	}

	/**
	 * The message text: one block per inline text part, in message order, each
	 * from its first candidate that is not blank.
	 *
	 * @param callable(Horde_Mime_Part): string $contents a part's decoded UTF-8 contents
	 */
	public static function text(Horde_Mime_Part $root, callable $contents): string {
		$root->buildMimeIds();
		$blocks = [];
		foreach (self::slots($root) as $candidates) {
			foreach ($candidates as $candidate) {
				$text = $contents($candidate['part']);
				if ($candidate['html']) {
					$text = Html::toText($text);
				}
				$text = trim($text);
				if ($text !== '') {
					$blocks[] = $text;
					break;
				}
			}
		}
		return implode("\n\n", $blocks);
	}

	/**
	 * A part's text as valid UTF-8, whatever charset it was labelled with.
	 *
	 * mbstring is tried first and iconv second: mbstring lacks several charsets
	 * real mail uses (windows-1256, for one), and on PHP 8 it *throws* on a
	 * charset it does not know rather than returning false — `@` does not stop
	 * a ValueError, and uncaught it ended the whole mailbox read. Whatever
	 * happens, the result is scrubbed: bytes that are not UTF-8 would otherwise
	 * reach the database and the prompt.
	 */
	public static function toUtf8(string $text, string $charset): string {
		$charset = strtoupper(trim($charset));
		if ($charset !== '' && !in_array($charset, ['UTF-8', 'US-ASCII', 'ASCII'], true)) {
			$text = self::convert($text, $charset) ?? $text;
		}
		return mb_scrub($text, 'UTF-8');
	}

	private static function convert(string $text, string $charset): ?string {
		try {
			return mb_convert_encoding($text, 'UTF-8', $charset);
		} catch (\ValueError) {
			// Not a charset mbstring knows; iconv may.
		}
		if (function_exists('iconv')) {
			$converted = @iconv($charset, 'UTF-8//IGNORE', $text);
			if ($converted !== false) {
				return $converted;
			}
		}
		return null;
	}

	/**
	 * One line per part — id, type, disposition, size — so an email that yielded
	 * no text can be diagnosed from the activity log without the email itself.
	 */
	public static function describe(Horde_Mime_Part $root): string {
		$root->buildMimeIds();
		$lines = [];
		foreach (self::all($root) as $part) {
			$line = $part->getMimeId() . ' ' . $part->getType();
			$disposition = $part->getDisposition();
			if ($disposition !== '') {
				$line .= ' (' . $disposition . ')';
			}
			if ($part->getPrimaryType() !== 'multipart') {
				$line .= ', ' . $part->getBytes() . ' bytes';
			}
			$lines[] = $line;
		}
		return implode("\n", $lines);
	}

	/**
	 * The text-bearing positions in the message. Each slot lists the parts that
	 * could fill it, best first: a lone text part is a slot of one, an
	 * alternative is one slot of its plain then its HTML renderings.
	 *
	 * @return list<list<array{part: Horde_Mime_Part, html: bool}>>
	 */
	private static function slots(Horde_Mime_Part $part): array {
		$type = $part->getType();
		if ($type === 'multipart/alternative') {
			$leaves = self::textLeaves($part);
			usort($leaves, static fn (array $a, array $b): int => $a['html'] <=> $b['html']);
			return $leaves === [] ? [] : [$leaves];
		}
		if ($part->getPrimaryType() === 'multipart' || $type === 'message/rfc822') {
			// An attached message is followed whatever its disposition: on a
			// forward-as-attachment it *is* the content.
			$slots = [];
			foreach (self::children($part) as $child) {
				array_push($slots, ...self::slots($child));
			}
			return $slots;
		}
		$leaf = self::textLeaf($part);
		return $leaf === null ? [] : [[$leaf]];
	}

	/**
	 * Every inline text part under an alternative, flattened — its HTML half is
	 * often a multipart/related holding the page and its images.
	 *
	 * @return list<array{part: Horde_Mime_Part, html: bool}>
	 */
	private static function textLeaves(Horde_Mime_Part $part): array {
		$leaves = [];
		foreach (self::children($part) as $child) {
			if ($child->getPrimaryType() === 'multipart' || $child->getType() === 'message/rfc822') {
				array_push($leaves, ...self::textLeaves($child));
				continue;
			}
			$leaf = self::textLeaf($child);
			if ($leaf !== null) {
				$leaves[] = $leaf;
			}
		}
		return $leaves;
	}

	/** @return array{part: Horde_Mime_Part, html: bool}|null */
	private static function textLeaf(Horde_Mime_Part $part): ?array {
		// A text file attached to a mail is a document, not the mail.
		if ($part->getDisposition() === 'attachment') {
			return null;
		}
		return match ($part->getType()) {
			'text/plain' => ['part' => $part, 'html' => false],
			'text/html' => ['part' => $part, 'html' => true],
			default => null,
		};
	}

	/**
	 * Horde types its children as a bare array; this is the typed view of it.
	 *
	 * @return list<Horde_Mime_Part>
	 */
	private static function children(Horde_Mime_Part $part): array {
		return array_values(array_filter(
			$part->getParts(),
			static fn (mixed $child): bool => $child instanceof Horde_Mime_Part,
		));
	}

	/**
	 * The part and everything under it, depth first.
	 *
	 * @return list<Horde_Mime_Part>
	 */
	private static function all(Horde_Mime_Part $part): array {
		$parts = [$part];
		foreach (self::children($part) as $child) {
			array_push($parts, ...self::all($child));
		}
		return $parts;
	}
}
