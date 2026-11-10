<?php

declare(strict_types=1);

namespace OCA\TravelManager\Pdf;

/**
 * The layout decisions made on PDF text, kept free of the parsing library so
 * they can be tested on plain numbers.
 *
 * The library places words correctly, but joins everything on one line with a
 * single space, so the columns of a table run together: a shuttle ticket's
 * origin address merged into its destination, and a boarding pass's two luggage
 * allowances swapped weights. A tab where the gap is wide is the whole fix — the
 * model reads it as a column break, as it does with `pdftotext -layout`.
 */
final class PdfLayout {
	/**
	 * A gap wider than this many ems is a column break, not a word space.
	 *
	 * Measured on three real tickets: gaps inside running text were at most
	 * 0.43 em (a boarding pass's luggage line), gaps between columns at least
	 * 1.14 em (the same line), and 1.88 / 2.31 / 4.69 em across an Amadeus
	 * e-ticket's flight row. 0.8 sits between the two with room either side.
	 */
	public const COLUMN_GAP_EM = 0.8;

	/**
	 * What goes between two pieces of text on one line.
	 *
	 * @param float $gap the horizontal distance from the end of the previous piece to the start of this one
	 * @param float $height the font height, so the gap can be judged in ems
	 * @param bool $wordSpace whether the library judged the gap a word break
	 */
	public static function separator(float $gap, float $height, bool $wordSpace): string {
		if ($height > 0 && $gap > self::COLUMN_GAP_EM * $height) {
			return "\t";
		}
		return $wordSpace ? ' ' : '';
	}

	/**
	 * One document's text from its pages' text, normalised as an email body is:
	 * unified line endings, no trailing blanks on a line, no runs of blank lines.
	 * Tabs inside a line are kept — they are the column breaks.
	 *
	 * @param list<string> $pages
	 */
	public static function document(array $pages): string {
		$text = implode("\n\n", array_map('trim', $pages));
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		return trim($text);
	}
}
