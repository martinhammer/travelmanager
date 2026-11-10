<?php

declare(strict_types=1);

namespace Pdf;

use OCA\TravelManager\Exception\PdfException;
use OCA\TravelManager\Pdf\PrinsPdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Reads the synthetic PDFs in tests/fixtures/pdf, which generate.php rebuilds
 * from the measurements taken on real tickets (the tickets themselves stay out
 * of the repository).
 */
final class PrinsPdfReaderTest extends TestCase {
	private static function read(string $fixture, int $maxPages = 10): \OCA\TravelManager\Pdf\PdfText {
		$bytes = file_get_contents(__DIR__ . '/../../fixtures/pdf/' . $fixture);
		self::assertIsString($bytes);
		return (new PrinsPdfReader())->read($bytes, $maxPages);
	}

	/**
	 * The Amadeus construction: every word drawn on its own, no space glyphs.
	 * smalot/pdfparser returned `MH120406:40` here, and the model read flight
	 * MH120 at the wrong times.
	 */
	public function testSeparatelyDrawnWordsAreSpacedAndTheColumnKept(): void {
		$this->assertSame("Booking ref: DM4R6O\nMH1204 06:40\t07:45", self::read('words.pdf')->text);
	}

	public function testColumnsAreSeparatedByATab(): void {
		$this->assertSame(
			"Small soft bag or\tHold Luggage\nCharleroi Airport (CRL)\tGhent",
			self::read('columns.pdf')->text,
		);
	}

	public function testOnlyTheFirstPagesAreRead(): void {
		$text = self::read('three-pages.pdf', 2);

		$this->assertSame(3, $text->pages);
		$this->assertSame(2, $text->pagesRead);
		$this->assertSame("Page one\n\nPage two", $text->text);
	}

	public function testAPageWithoutTextReadsAsEmpty(): void {
		$text = self::read('no-text.pdf');

		$this->assertSame(1, $text->pagesRead);
		$this->assertSame('', $text->text);
	}

	public function testAPasswordProtectedFileIsReportedAsEncrypted(): void {
		try {
			self::read('encrypted-password.pdf');
			$this->fail('Expected a PdfException');
		} catch (PdfException $e) {
			$this->assertTrue($e->encrypted);
		}
	}

	/**
	 * An owner-only file (printing and copying restricted) opens in every viewer
	 * without a password, and should be read. As of prinsfrank/pdfparser 4.3.0 it
	 * is not: RC4 streams fail to decrypt even with the right password. Until
	 * that is fixed it must at least be reported as encrypted rather than as a
	 * broken file. If this test starts failing because the text comes back,
	 * the library has been fixed — assert the text instead.
	 */
	public function testAnOwnerOnlyRc4FileIsReportedAsEncryptedForNow(): void {
		try {
			self::read('encrypted-owner-only.pdf');
			$this->fail('The library can read owner-only RC4 files now: assert the text instead');
		} catch (PdfException $e) {
			$this->assertTrue($e->encrypted);
		}
	}

	public function testBytesThatAreNotAPdfAreRefused(): void {
		$this->expectException(PdfException::class);
		(new PrinsPdfReader())->read("Dear traveller,\nyour ticket is attached.", 10);
	}

	public function testATruncatedPdfIsAFailureNotACrash(): void {
		$bytes = file_get_contents(__DIR__ . '/../../fixtures/pdf/words.pdf');
		self::assertIsString($bytes);

		try {
			(new PrinsPdfReader())->read(substr($bytes, 0, 300), 10);
			$this->fail('Expected a PdfException');
		} catch (PdfException $e) {
			$this->assertFalse($e->encrypted);
		}
	}
}
