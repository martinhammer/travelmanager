<?php

declare(strict_types=1);

namespace Db;

use OCA\TravelManager\Db\ColumnText;
use PHPUnit\Framework\TestCase;

final class ColumnTextTest extends TestCase {
	public function testFitCutsToTheColumnInCharactersNotBytes(): void {
		// 300 two-byte characters: a byte cut would land mid-character, and a
		// character count is what both MySQL utf8mb4 and PostgreSQL enforce.
		$fitted = (string)ColumnText::fit(str_repeat('é', 300));
		$this->assertSame(255, mb_strlen($fitted, 'UTF-8'));
		$this->assertTrue(mb_check_encoding($fitted, 'UTF-8'));
	}

	public function testFitLeavesAShortValueAndNullAlone(): void {
		$this->assertSame('Flight to Lisbon', ColumnText::fit('Flight to Lisbon'));
		$this->assertNull(ColumnText::fit(null));
	}

	public function testCleanRemovesWhatADatabaseRefuses(): void {
		// PostgreSQL refuses NUL in any text column; every database refuses
		// invalid UTF-8.
		$clean = ColumnText::clean("Gate\0 B12 \xC3\x28");
		$this->assertStringNotContainsString("\0", $clean);
		$this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
		$this->assertStringStartsWith('Gate B12 ', $clean);
	}

	public function testFitsIsTheCheckForWhatAUserTyped(): void {
		$this->assertTrue(ColumnText::fits(str_repeat('a', 255)));
		$this->assertFalse(ColumnText::fits(str_repeat('a', 256)));
		$this->assertTrue(ColumnText::fits(str_repeat('é', 255)));
		$this->assertTrue(ColumnText::fits(null));
	}
}
