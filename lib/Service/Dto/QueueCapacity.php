<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * How many queued messages may go to the model right now, and which limit
 * decided it. See {@see \OCA\TravelManager\Service\QueuePlanner::capacity()}.
 */
class QueueCapacity {
	/** The in-flight cap: too many of our tasks are already waiting on the model. */
	public const LIMIT_IN_FLIGHT = 'in_flight';
	/** The hourly budget: we have started as many tasks this hour as allowed. */
	public const LIMIT_HOURLY = 'hourly';
	/**
	 * No provider can take text2text tasks at all. Never produced by the planner —
	 * ExtractionQueue checks this before asking it — but it is why work waits, so
	 * it is reported the same way.
	 */
	public const LIMIT_NO_PROVIDER = 'no_provider';

	public function __construct(
		public readonly int $slots,
		/**
		 * The binding limit — the one to name when work has to wait. Always set,
		 * even with free slots: it is what will bind first if the queue is longer
		 * than the slots.
		 *
		 * @var self::LIMIT_*
		 */
		public readonly string $limitedBy,
	) {
	}
}
