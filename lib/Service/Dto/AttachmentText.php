<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * The attachments of one email as the pipeline uses them: the sections that go
 * into the prompt, and the record of every PDF found, read or not.
 */
final class AttachmentText {
	public function __construct(
		/** @var list<string> from MessageText::section, in message order */
		public readonly array $sections = [],
		/** @var list<AttachmentRecord> one per PDF found, including the skipped ones */
		public readonly array $records = [],
	) {
	}
}
