<?php

declare(strict_types=1);

namespace Imap;

use OCA\TravelManager\Imap\MessageId;
use PHPUnit\Framework\TestCase;

final class MessageIdTest extends TestCase {
	public function testAnOrdinaryMessageIdIsKeptAsItIs(): void {
		$this->assertSame('<abc123@mail.example.com>', MessageId::key(' <abc123@mail.example.com> ', 3, 7));
	}

	public function testAMissingMessageIdFallsBackToTheUid(): void {
		$this->assertSame('<tm-3-7@travelmanager.local>', MessageId::key('', 3, 7));
	}

	public function testAnOverLongMessageIdIsHashedNotCut(): void {
		// Two ids sharing their first 255 characters must stay two emails, which
		// a cut would make one: the second would then never be read.
		$prefix = '<' . str_repeat('x', 260);
		$first = MessageId::key($prefix . 'one@example.com>', 3, 7);
		$second = MessageId::key($prefix . 'two@example.com>', 3, 8);

		$this->assertNotSame($first, $second);
		$this->assertLessThanOrEqual(255, strlen($first));
		$this->assertMatchesRegularExpression('/^<tm-sha1-[0-9a-f]{40}@travelmanager\.local>$/', $first);
	}

	public function testTheStandInIsStableSoTheEmailIsRecognisedNextRun(): void {
		$long = '<' . str_repeat('y', 300) . '@example.com>';
		// Stable across UIDVALIDITY too, unlike the no-Message-ID fallback.
		$this->assertSame(MessageId::key($long, 3, 7), MessageId::key($long, 9, 42));
	}

	public function testAnIdThatIsNotValidUtf8IsHashed(): void {
		$this->assertStringStartsWith('<tm-sha1-', MessageId::key("<caf\xE9@example.com>", 3, 7));
	}
}
