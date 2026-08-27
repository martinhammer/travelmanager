<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service\Dto;

/**
 * The outcome of validating one LLM response: the bookings that survived, plus
 * a record of everything that was rejected or trimmed on the way.
 *
 * An empty {@see $bookings} with no {@see $issues} means the model genuinely
 * found no booking in the email. An empty one *with* issues means it did find
 * something we then refused — a very different thing, and the case worth
 * retrying or tuning the prompt against.
 */
class ExtractionResult {
	/**
	 * @param ExtractedBooking[] $bookings
	 * @param ExtractionIssue[] $issues
	 */
	public function __construct(
		public readonly array $bookings,
		public readonly array $issues = [],
	) {
	}

	/** Number of bookings the model produced that we refused entirely. */
	public function droppedCount(): int {
		return count(array_filter($this->issues, static fn (ExtractionIssue $i): bool => $i->dropped));
	}

	/**
	 * The distinct REASON_* slugs hit, for storing on the message. Machine-facing
	 * counterpart to describeIssues() — the UI branches on these rather than
	 * reading the prose back.
	 *
	 * @return list<string>
	 */
	public function reasonSlugs(): array {
		$slugs = array_map(static fn (ExtractionIssue $i): string => $i->reason, $this->issues);
		return array_values(array_unique($slugs));
	}

	/** One line per issue, for the activity log's context block. */
	public function describeIssues(): string {
		return implode("\n", array_map(
			static fn (ExtractionIssue $i): string => '- [' . $i->reason . '] ' . $i->description,
			$this->issues,
		));
	}
}
