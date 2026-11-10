<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Service\Dto\AttachmentRecord;
use PHPUnit\Framework\TestCase;

final class AttachmentRecordTest extends TestCase {
	public function testAnEmailWithoutPdfsStoresNull(): void {
		$this->assertNull(AttachmentRecord::encodeList([]));
		$this->assertSame([], AttachmentRecord::decodeList(null));
	}

	public function testRecordsSurviveTheColumn(): void {
		$json = AttachmentRecord::encodeList([
			new AttachmentRecord('Électronique.pdf', AttachmentRecord::OUTCOME_READ, 2, 2, 3591),
			new AttachmentRecord(null, AttachmentRecord::OUTCOME_TOO_LARGE),
		]);

		$this->assertSame([
			['filename' => 'Électronique.pdf', 'pages' => 2, 'pagesRead' => 2, 'chars' => 3591, 'outcome' => 'read', 'truncated' => false],
			['filename' => null, 'pages' => null, 'pagesRead' => null, 'chars' => null, 'outcome' => 'too_large', 'truncated' => false],
		], AttachmentRecord::decodeList($json));
	}

	/** A display aid must never fail the message list it sits in. */
	public function testMalformedValuesAreDroppedNotThrown(): void {
		$this->assertSame([], AttachmentRecord::decodeList('{not json'));
		$this->assertSame([], AttachmentRecord::decodeList('"a string"'));
		$this->assertSame(
			[['filename' => null, 'pages' => null, 'pagesRead' => null, 'chars' => null, 'outcome' => 'read', 'truncated' => false]],
			AttachmentRecord::decodeList('[{"outcome":"read","pages":"2","filename":3}, {"outcome":"exploded"}, 7]'),
		);
	}
}
