<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * One queued ledger row, as far as the planner needs to know it: which user it
 * belongs to and its row id. Plain values so {@see \OCA\TravelManager\Service\QueuePlanner}
 * stays free of OCP types.
 */
class QueuedMessage {
	public function __construct(
		public readonly string $userId,
		/** `messages.id` — insertion order, so oldest first by construction. */
		public readonly int $id,
	) {
	}
}
