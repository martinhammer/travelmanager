<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * A booking already in the database, as far as the import planner needs to know
 * it. Plain values so {@see \OCA\TravelManager\Service\BackupImportPlanner} stays
 * free of OCP types.
 */
class ExistingBooking {
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly ?string $sourceMessageId,
		public readonly ?int $duplicateGroupId,
	) {
	}
}
