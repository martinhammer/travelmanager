<?php

declare(strict_types=1);

namespace OCA\TravelManager\Pdf;

use OCA\TravelManager\Exception\PdfException;

/**
 * Turns the bytes of a PDF attachment into text for the model.
 *
 * The seam exists so the parsing library sits behind one class: the library
 * moves through major versions quickly, and the pieces of it the
 * implementation uses are public but not promised stable across them.
 */
interface IPdfReader {
	/**
	 * @param int $maxPages read at most this many pages, from the first
	 * @throws PdfException when the bytes are not a readable PDF
	 */
	public function read(string $bytes, int $maxPages): PdfText;
}
