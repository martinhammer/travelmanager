<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Service\MessageText;
use PHPUnit\Framework\TestCase;

final class MessageTextTest extends TestCase {
	public function testWithoutAttachmentsTheBodyKeepsItsOldCap(): void {
		$body = str_repeat('a', MessageText::BODY_ALONE + 500);

		$text = MessageText::assemble($body, []);

		$this->assertSame(MessageText::BODY_ALONE, strlen($text));
		$this->assertStringEndsWith("\n[… truncated]", $text);
	}

	public function testAShortBodyIsUntouched(): void {
		$this->assertSame('Your ticket is attached.', MessageText::assemble("  Your ticket is attached.\n", []));
	}

	public function testSectionsFollowTheBodyInOrder(): void {
		$text = MessageText::assemble('Your ticket is attached.', ['--- ATTACHMENT: a.pdf ---', '--- ATTACHMENT: b.pdf ---']);

		$this->assertSame("Your ticket is attached.\n\n--- ATTACHMENT: a.pdf ---\n\n--- ATTACHMENT: b.pdf ---", $text);
	}

	public function testABlankBodyLeavesOnlyTheSections(): void {
		$this->assertSame('--- ATTACHMENT: a.pdf ---', MessageText::assemble("  \n", ['--- ATTACHMENT: a.pdf ---']));
	}

	/** Attachments carry the booking whenever they exist, so the body gives way. */
	public function testTheBodyGivesWayToAttachmentsWithinTheTotal(): void {
		$sections = array_fill(0, 3, str_repeat('p', MessageText::PER_ATTACHMENT));
		$body = str_repeat('b', MessageText::BODY_ALONE);

		$text = MessageText::assemble($body, $sections);

		$this->assertLessThanOrEqual(MessageText::TOTAL, strlen($text));
		$this->assertStringContainsString("[… truncated]\n\n" . $sections[0], $text);
		$this->assertStringEndsWith($sections[2], $text);
	}

	public function testTheBodyNeverGetsMoreThanItWouldAlone(): void {
		$body = str_repeat('b', MessageText::TOTAL);

		$text = MessageText::assemble($body, ['--- ATTACHMENT: small.pdf ---']);

		$this->assertSame(MessageText::BODY_ALONE + 2 + strlen('--- ATTACHMENT: small.pdf ---'), strlen($text));
	}

	public function testTruncationNeverSplitsACharacter(): void {
		[$text, $cut] = MessageText::truncate(str_repeat('é', 100), 51);

		$this->assertTrue($cut);
		$this->assertTrue(mb_check_encoding($text, 'UTF-8'));
		$this->assertLessThanOrEqual(51, strlen($text));
	}

	public function testTextWithinTheLimitIsNotCut(): void {
		$this->assertSame(['short', false], MessageText::truncate('short', 5));
	}

	public function testSectionHeadingsSayHowMuchWasRead(): void {
		$this->assertSame("--- ATTACHMENT: e-ticket.pdf (PDF, 1 page) ---\nMH1204", MessageText::section('e-ticket.pdf', 1, 1, 1, 'MH1204'));
		$this->assertSame("--- ATTACHMENT: attachment 2 (PDF, 3 pages) ---\nx", MessageText::section(null, 2, 3, 3, 'x'));
		$this->assertSame("--- ATTACHMENT: terms.pdf (PDF, first 10 of 40 pages) ---\nx", MessageText::section('terms.pdf', 1, 40, 10, 'x'));
	}
}
