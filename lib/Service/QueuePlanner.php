<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Service\Dto\QueueCapacity;
use OCA\TravelManager\Service\Dto\QueuedMessage;

/**
 * Decides which queued messages go to the model now. **Pure and dependency-free**,
 * like ExtractionService and BookingMatcher, so the rules run under the standalone
 * PHPUnit bootstrap; {@see ExtractionQueue} does the I/O around it.
 *
 * Two independent limits, because they protect different things:
 * - **in flight** bounds how many of our tasks sit in Task Processing at once.
 *   That is what keeps a large backlog from flooding the queue every other app
 *   shares, and what bounds concurrency on a local model.
 * - **per hour** bounds how many we *start*. A cap never slows anything down —
 *   each completion frees a slot and the next task goes immediately, so the rate
 *   is whatever the provider's latency makes it — and an external provider's
 *   limit is a rate. The hour is deliberately coarse: this code only runs on a
 *   cron tick or a task event, never on a timer of its own, so a per-minute rate
 *   is not something it could honour.
 */
class QueuePlanner {
	/**
	 * How many queued messages may be scheduled now.
	 *
	 * Negative headroom — more in flight than the cap allows, which a lowered
	 * setting or a race between two pumps can leave behind — is zero slots, never
	 * a negative number to subtract from.
	 *
	 * @param int $inFlight our tasks scheduled and not yet heard back from
	 * @param int $maxInFlight the cap; at least 1
	 * @param int $startedLastHour tasks we scheduled in the trailing 60 minutes
	 * @param int $maxPerHour the hourly budget; 0 means unlimited
	 */
	public function capacity(int $inFlight, int $maxInFlight, int $startedLastHour, int $maxPerHour): QueueCapacity {
		$byInFlight = max(0, max(1, $maxInFlight) - $inFlight);
		if ($maxPerHour <= 0) {
			return new QueueCapacity($byInFlight, QueueCapacity::LIMIT_IN_FLIGHT);
		}

		$byHour = max(0, $maxPerHour - $startedLastHour);
		// On a tie the in-flight cap is the one named: it is the limit that frees
		// up soonest, so "waiting for a free slot" is the more useful thing to say.
		return $byHour < $byInFlight
			? new QueueCapacity($byHour, QueueCapacity::LIMIT_HOURLY)
			: new QueueCapacity($byInFlight, QueueCapacity::LIMIT_IN_FLIGHT);
	}

	/**
	 * Choose up to `$slots` messages, **taking turns across users**.
	 *
	 * The limits are instance-wide — the provider and the shared queue are — so
	 * one user's first-enrolment backfill must not starve everyone else's new
	 * mail. Users are served in order of their longest-waiting message, one each
	 * per round. Within a user it is strictly oldest first, because that is the
	 * order booking deduplication relies on (the first email about a booking is
	 * the one that creates it — see CLAUDE.md §7).
	 *
	 * Keyed `array-key`, not `string`, on purpose: PHP turns a numeric-string key
	 * into an int, and Nextcloud user ids can be numeric (LDAP backends do it), so
	 * a string-typed comparator here would throw under strict_types.
	 *
	 * @param array<array-key, list<int>> $queued each user's queued row ids, oldest first
	 * @return list<QueuedMessage>
	 */
	public function pick(array $queued, int $slots): array {
		$queued = array_filter($queued, static fn (array $ids): bool => $ids !== []);
		if ($slots <= 0 || $queued === []) {
			return [];
		}

		// Longest-waiting user first. Row ids are insertion order, so the lowest
		// head is the message that has waited longest; the user id breaks a tie
		// only so the order is total and a test can pin it.
		$users = array_map(static fn (int|string $key): string => (string)$key, array_keys($queued));
		usort($users, static function (string $a, string $b) use ($queued): int {
			return [$queued[$a][0], $a] <=> [$queued[$b][0], $b];
		});

		$picked = [];
		for ($round = 0; count($picked) < $slots; $round++) {
			$tookAny = false;
			foreach ($users as $userId) {
				if (!isset($queued[$userId][$round])) {
					continue;
				}
				$picked[] = new QueuedMessage($userId, $queued[$userId][$round]);
				$tookAny = true;
				if (count($picked) === $slots) {
					break;
				}
			}
			if (!$tookAny) {
				break;
			}
		}
		return $picked;
	}
}
