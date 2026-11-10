<?php

declare(strict_types=1);

namespace OCA\TravelManager\Pdf;

/**
 * The text read from one PDF, and how much of the document it covers.
 */
final class PdfText {
	public function __construct(
		/** Pages in the document. */
		public readonly int $pages,
		/** Pages actually read; fewer than $pages when the page cap applied. */
		public readonly int $pagesRead,
		/** Normalised text, empty when the document has no text layer. */
		public readonly string $text,
	) {
	}
}
