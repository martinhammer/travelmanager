<?php

declare(strict_types=1);

namespace OCA\TravelManager\Pdf;

use OCA\TravelManager\Exception\PdfException;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Exception\AuthenticationFailedException;
use PrinsFrank\PdfParser\Extraction\SpaceDetection\SpaceDetector;
use PrinsFrank\PdfParser\Extraction\TextGrouping\TextGrouping;
use PrinsFrank\PdfParser\PdfParser;

/**
 * Reads PDF text with prinsfrank/pdfparser. The only class that imports it.
 *
 * Chosen over smalot/pdfparser, which the Assistant app uses: smalot estimates
 * glyph widths and only spaces a gap of two ems or more, so an Amadeus e-ticket
 * — which draws every word separately with no space glyphs — came out as
 * `MH120406:40`, and the model read flight MH120 at the wrong times. This
 * library computes real advance widths and breaks words at a quarter em.
 *
 * Lines are built from the library's own pieces, as its TextExtractor does,
 * with one addition: {@see PdfLayout::separator} marks column breaks. Never
 * use the library's Markdown output — it inlines link targets, and a booking's
 * manage-my-booking link can carry an access token.
 */
class PrinsPdfReader implements IPdfReader {
	/** The PDF spec lets a header sit anywhere in the first kilobyte. */
	private const HEADER_WINDOW = 1024;

	public function read(string $bytes, int $maxPages): PdfText {
		if (!str_contains(substr($bytes, 0, self::HEADER_WINDOW), '%PDF-')) {
			throw new PdfException('Not a PDF file');
		}

		try {
			// The library decrypts with the empty user password by itself, which
			// is how a viewer opens a file that is only owner-protected (no
			// printing or copying) without asking. It throws only when a real
			// password is needed, and an email attachment arrives with none.
			$document = (new PdfParser())->parseString($bytes);
			$pages = $document->getPages();
			$texts = [];
			foreach (array_slice($pages, 0, max(0, $maxPages)) as $page) {
				$texts[] = $this->pageText($page);
			}
		} catch (AuthenticationFailedException $e) {
			throw new PdfException('The PDF is protected by a password', true, $e);
		} catch (PdfException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// An encrypted file that fails anywhere is reported as encrypted: as of
			// 4.3.0 the library opens an RC4 file without asking but then cannot
			// decrypt its streams, even given the right password, and "protected"
			// says more to the user than a decompression error.
			if (str_contains($bytes, '/Encrypt')) {
				throw new PdfException('The PDF is encrypted and could not be decrypted', true, $e);
			}
			// Malformed files surface as anything from the library's own
			// exceptions to a TypeError deep in a decoder; to the caller they are
			// all one thing.
			throw new PdfException('Could not read the PDF: ' . $e->getMessage(), false, $e);
		}

		return new PdfText(count($pages), count($texts), PdfLayout::document($texts));
	}

	private function pageText(Page $page): string {
		/** @var array<string, Font> $fontCache */
		$fontCache = [];
		$lines = [];
		foreach (TextGrouping::group($page->getPositionedTextElements(), $page, $fontCache) as $elements) {
			$lines[] = $this->lineText($elements, $page, $fontCache);
		}
		return implode("\n", $lines);
	}

	/**
	 * @param list<PositionedTextElement> $elements one line, left to right
	 * @param array<string, Font> $fontCache
	 */
	private function lineText(array $elements, Page $page, array &$fontCache): string {
		$line = '';
		$previous = null;
		$previousFont = null;
		foreach ($elements as $element) {
			$fontName = $element->textState->fontName;
			if ($fontName === null) {
				// A piece with no font cannot be decoded. The library gives up on
				// the whole page here; losing the piece is the smaller loss.
				continue;
			}
			$font = $fontCache[$fontName->value] ??= $page->getFont($fontName);
			$text = $element->getText($font);
			if ($text === '') {
				continue;
			}

			if ($previous !== null && $previousFont !== null) {
				$gap = $element->absoluteMatrix->offsetX - $previous->getRight($page, $fontCache);
				$wordSpace = !str_ends_with($line, ' ') && !str_starts_with($text, ' ')
					&& SpaceDetector::shouldContainExtraSpace($element, $previous, $previousFont);
				$separator = PdfLayout::separator($gap, $element->getHeight(), $wordSpace);
				if ($separator === "\t") {
					$line = rtrim($line, ' ');
					$text = ltrim($text, ' ');
				}
				$line .= $separator;
			}

			$line .= $text;
			$previous = $element;
			$previousFont = $font;
		}
		return $line;
	}
}
