<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

/**
 * Minimal, dependency-free HTML-to-text conversion for email bodies that only
 * ship an HTML part. Good enough to feed an LLM (we want the readable text, not
 * a faithful render). Kept pure so it is unit-testable in isolation.
 */
class Html {
	public static function toText(string $html): string {
		// Drop script/style blocks (content included).
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
		// Turn line-breaking / block-closing tags into newlines.
		$html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
		$html = preg_replace('#</(p|div|tr|li|h[1-6]|table|ul|ol)\s*>#i', "\n", $html) ?? $html;
		// Strip the rest of the tags.
		$text = strip_tags($html);
		// Decode entities (&nbsp;, &amp;, &#8230; …).
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Normalise whitespace: collapse spaces, trim each line, cap blank runs.
		$text = str_replace(["\r\n", "\r", "\u{00a0}"], ["\n", "\n", ' '], $text);
		$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
		$text = preg_replace('/ *\n */', "\n", $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		return trim($text);
	}
}
