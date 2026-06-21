<?php

declare(strict_types=1);

namespace Imap;

use OCA\TravelManager\Imap\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase {
	public function testStripsTagsAndKeepsText(): void {
		$html = '<p>Your flight <strong>SK1234</strong> is confirmed.</p>';
		$this->assertSame('Your flight SK1234 is confirmed.', Html::toText($html));
	}

	public function testBreaksAndBlocksBecomeNewlines(): void {
		$html = 'Departure: OSL<br>Arrival: CPH<br/>Seat: 14C';
		$this->assertSame("Departure: OSL\nArrival: CPH\nSeat: 14C", Html::toText($html));
	}

	public function testParagraphsSeparated(): void {
		$html = '<p>Booking ABC123</p><p>Gate D7</p>';
		$this->assertSame("Booking ABC123\nGate D7", Html::toText($html));
	}

	public function testDropsScriptAndStyle(): void {
		$html = '<style>.x{color:red}</style><script>alert(1)</script><div>Hotel Plaza</div>';
		$this->assertSame('Hotel Plaza', Html::toText($html));
	}

	public function testDecodesEntities(): void {
		$html = '<div>Smith &amp; Co &mdash; room&nbsp;204</div>';
		$this->assertSame('Smith & Co — room 204', Html::toText($html));
	}

	public function testCollapsesWhitespaceAndBlankLines(): void {
		// Multiple spaces collapse to one; a long run of newlines collapses to a
		// single blank line (one paragraph break is preserved).
		$html = "<div>Line   one</div>\n\n\n\n<div>Line two</div>";
		$this->assertSame("Line one\n\nLine two", Html::toText($html));
	}

	public function testEmptyInput(): void {
		$this->assertSame('', Html::toText(''));
	}
}
