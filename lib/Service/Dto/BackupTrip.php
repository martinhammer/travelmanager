<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One validated trip from a backup file. Dates are local wall-clock
 * `Y-m-d\TH:i:s` strings (no offset, V8).
 */
class BackupTrip {
	public function __construct(
		/** The id on the exporting server: only meaningful inside the file. */
		public readonly int $id,
		public readonly string $name,
		public readonly ?string $type,
		public readonly ?string $color,
		public readonly ?string $startDate,
		public readonly ?string $endDate,
		public readonly ?string $notes,
		public readonly ?string $createdAt,
		public readonly ?string $updatedAt,
	) {
	}
}
