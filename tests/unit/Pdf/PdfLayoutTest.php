<?php

declare(strict_types=1);

namespace Pdf;

use OCA\TravelManager\Pdf\PdfLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfLayoutTest extends TestCase {
	private const HEIGHT = 9.0;

	/**
	 * Gaps measured on real tickets, in ems. Anything up to 0.43 em was inside
	 * running text; 1.14 em upwards was between columns.
	 *
	 * @return array<string, array{float, string}>
	 */
	public static function measuredGaps(): array {
		return [
			'a gap around a colon (Amadeus "06:40")' => [0.05, ''],
			'a word gap (Amadeus)' => [0.32, ' '],
			'the widest word gap (boarding pass)' => [0.43, ' '],
			'the narrowest column gap (boarding pass luggage)' => [1.14, "\t"],
			'origin to destination (Amadeus)' => [1.88, "\t"],
			'destination to flight (Amadeus)' => [2.31, "\t"],
			'departure to arrival (Amadeus)' => [4.69, "\t"],
		];
	}

	#[DataProvider('measuredGaps')]
	public function testMeasuredGapsAreSeparatedAsTheTicketsNeed(float $ems, string $expected): void {
		// The library's own word decision is a space from a quarter em up.
		$wordSpace = $ems >= 0.25;
		$this->assertSame($expected, PdfLayout::separator($ems * self::HEIGHT, self::HEIGHT, $wordSpace));
	}

	public function testTheThresholdIsExclusive(): void {
		$atThreshold = PdfLayout::COLUMN_GAP_EM * self::HEIGHT;
		$this->assertSame(' ', PdfLayout::separator($atThreshold, self::HEIGHT, true));
		$this->assertSame("\t", PdfLayout::separator($atThreshold + 0.01, self::HEIGHT, true));
	}

	public function testBelowTheThresholdTheLibraryDecides(): void {
		$this->assertSame('', PdfLayout::separator(0.5 * self::HEIGHT, self::HEIGHT, false));
		$this->assertSame(' ', PdfLayout::separator(0.5 * self::HEIGHT, self::HEIGHT, true));
	}

	/** A piece with no measurable height cannot be judged in ems. */
	public function testAZeroHeightNeverMakesAColumn(): void {
		$this->assertSame(' ', PdfLayout::separator(100.0, 0.0, true));
	}

	public function testPagesAreJoinedByABlankLineAndKeepTheirTabs(): void {
		$this->assertSame(
			"MH1204 06:40\t07:45\n\nPage two",
			PdfLayout::document(["MH1204 06:40\t07:45\n", '  Page two  ']),
		);
	}

	public function testTrailingBlanksAndBlankRunsAreRemoved(): void {
		$this->assertSame(
			"Total:\tMYR 184.80\n\nNext",
			PdfLayout::document(["Total:\tMYR 184.80 \t\r\n\n\n\n\nNext"]),
		);
	}

	public function testADocumentWithoutTextIsEmpty(): void {
		$this->assertSame('', PdfLayout::document(['', " \n "]));
	}
}
