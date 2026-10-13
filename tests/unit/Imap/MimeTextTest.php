<?php

declare(strict_types=1);

namespace Imap;

use Horde_Mime_Part;
use OCA\TravelManager\Imap\MimeText;
use PHPUnit\Framework\TestCase;

final class MimeTextTest extends TestCase {
	private static function parse(string $headers, string $body): Horde_Mime_Part {
		$raw = "MIME-Version: 1.0\r\n" . $headers . "\r\n\r\n" . str_replace("\n", "\r\n", $body);
		return Horde_Mime_Part::parseMessage($raw);
	}

	private static function text(Horde_Mime_Part $root): string {
		return MimeText::text($root, static fn (Horde_Mime_Part $part): string => (string)$part->getContents());
	}

	public function testASinglePartMessageIsItsOwnText(): void {
		$root = self::parse('Content-Type: text/plain; charset=utf-8', 'Flight FR123 on 1 November.');
		$this->assertSame('Flight FR123 on 1 November.', self::text($root));
	}

	/**
	 * The shape that lost a real booking: forwarded with nothing typed, so the
	 * first text part is an empty note and the original is an attached message.
	 */
	public function testAForwardWithAnEmptyNoteReadsTheAttachedMessage(): void {
		$root = self::parse('Content-Type: multipart/mixed; boundary="A"', <<<'EOT'
--A
Content-Type: multipart/alternative; boundary="B"

--B
Content-Type: text/plain; charset=utf-8


--B
Content-Type: text/html; charset=utf-8

<html><body><br></body></html>
--B--
--A
Content-Type: message/rfc822
Content-Disposition: attachment

Subject: Booking Summary and receipt Z6VSLM
MIME-Version: 1.0
Content-Type: text/html; charset=utf-8

<p>Booking Z6VSLM</p><p>Departs 2026-11-01 06:25</p>
--A--
EOT);
		$this->assertSame("Booking Z6VSLM\nDeparts 2026-11-01 06:25", self::text($root));
	}

	public function testAnAlternativeFallsBackToHtmlWhenItsPlainHalfIsBlank(): void {
		$root = self::parse('Content-Type: multipart/alternative; boundary="B"', <<<'EOT'
--B
Content-Type: text/plain; charset=utf-8


--B
Content-Type: text/html; charset=utf-8

<div>Hotel Plaza, check-in 12 Nov</div>
--B--
EOT);
		$this->assertSame('Hotel Plaza, check-in 12 Nov', self::text($root));
	}

	public function testAnAlternativeIsReadOnceAndPrefersPlain(): void {
		$root = self::parse('Content-Type: multipart/alternative; boundary="B"', <<<'EOT'
--B
Content-Type: text/plain; charset=utf-8

Plain rendering
--B
Content-Type: text/html; charset=utf-8

<p>HTML rendering</p>
--B--
EOT);
		$this->assertSame('Plain rendering', self::text($root));
	}

	public function testHtmlInsideARelatedPartStillCountsAsTheAlternative(): void {
		$root = self::parse('Content-Type: multipart/alternative; boundary="B"', <<<'EOT'
--B
Content-Type: text/plain; charset=utf-8


--B
Content-Type: multipart/related; boundary="C"

--C
Content-Type: text/html; charset=utf-8

<p>Car hire, pickup 3 Dec</p>
--C
Content-Type: image/png
Content-Disposition: inline

iVBORw0KGgo=
--C--
--B--
EOT);
		$this->assertSame('Car hire, pickup 3 Dec', self::text($root));
	}

	/** Apple Mail splits a body around an inline attachment into several text parts. */
	public function testEveryInlineTextPartIsKeptInOrder(): void {
		$root = self::parse('Content-Type: multipart/mixed; boundary="A"', <<<'EOT'
--A
Content-Type: text/plain; charset=utf-8

Begin forwarded message:
--A
Content-Type: application/pdf
Content-Disposition: inline; filename="ticket.pdf"

JVBERi0=
--A
Content-Type: text/plain; charset=utf-8

Train 9567, Brussels to London
--A--
EOT);
		$this->assertSame("Begin forwarded message:\n\nTrain 9567, Brussels to London", self::text($root));
	}

	public function testAnAttachedTextFileIsNotTheEmail(): void {
		$root = self::parse('Content-Type: multipart/mixed; boundary="A"', <<<'EOT'
--A
Content-Type: text/plain; charset=utf-8

See attached.
--A
Content-Type: text/plain; charset=utf-8; name="notes.txt"
Content-Disposition: attachment; filename="notes.txt"

Not part of the email
--A--
EOT);
		$this->assertSame('See attached.', self::text($root));
		$this->assertCount(1, MimeText::partIds($root));
	}

	public function testAMessageWithNoTextHasNothingToFetch(): void {
		$root = self::parse('Content-Type: multipart/mixed; boundary="A"', <<<'EOT'
--A
Content-Type: application/pdf
Content-Disposition: attachment; filename="ticket.pdf"

JVBERi0=
--A--
EOT);
		$this->assertSame([], MimeText::partIds($root));
		$this->assertSame('', self::text($root));
	}

	/** A real mailbox read died on this: mbstring throws on a charset it lacks. */
	public function testACharsetMbstringLacksIsConvertedWithoutThrowing(): void {
		$this->assertSame('ال', MimeText::toUtf8("\xC7\xE1", 'windows-1256'));
	}

	public function testACommonCharsetIsConverted(): void {
		$this->assertSame('café', MimeText::toUtf8("caf\xE9", 'ISO-8859-1'));
	}

	public function testAnUnknownCharsetStillYieldsValidUtf8(): void {
		$text = MimeText::toUtf8("ok \xFF\xFE", 'x-no-such-charset');
		$this->assertTrue(mb_check_encoding($text, 'UTF-8'));
		$this->assertStringStartsWith('ok ', $text);
	}

	public function testMislabelledUtf8IsScrubbed(): void {
		$this->assertTrue(mb_check_encoding(MimeText::toUtf8("bad \xC3", 'utf-8'), 'UTF-8'));
	}

	public function testDescribeListsEveryPart(): void {
		$root = self::parse('Content-Type: multipart/mixed; boundary="A"', <<<'EOT'
--A
Content-Type: text/plain; charset=utf-8

Hi
--A
Content-Type: application/pdf
Content-Disposition: attachment; filename="ticket.pdf"

JVBERi0=
--A--
EOT);
		$lines = explode("\n", MimeText::describe($root));
		$this->assertSame('0 multipart/mixed', $lines[0]);
		$this->assertStringStartsWith('1 text/plain, ', $lines[1]);
		$this->assertStringStartsWith('2 application/pdf (attachment), ', $lines[2]);
	}
}
