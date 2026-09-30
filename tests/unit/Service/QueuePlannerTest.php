<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Service\Dto\QueueCapacity;
use OCA\TravelManager\Service\Dto\QueuedMessage;
use OCA\TravelManager\Service\QueuePlanner;
use PHPUnit\Framework\TestCase;

/**
 * Which queued messages go to the model, and when. Pure — runs under the
 * standalone bootstrap.
 *
 * The distinction the whole class exists for: the in-flight cap bounds how much
 * of ours is *waiting*, the hourly budget bounds how often we *start*. A cap on
 * its own never slows anything down, which is why this morning's 429s happened
 * under one.
 */
final class QueuePlannerTest extends TestCase {
	private QueuePlanner $planner;

	protected function setUp(): void {
		parent::setUp();
		$this->planner = new QueuePlanner();
	}

	/**
	 * @param list<QueuedMessage> $picked
	 * @return list<string>
	 */
	private function describe(array $picked): array {
		return array_map(static fn (QueuedMessage $m): string => $m->userId . ':' . $m->id, $picked);
	}

	/* ------------------------------------------------------------ capacity */

	public function testFreeSlotsAreTheCapMinusWhatIsInFlight(): void {
		$capacity = $this->planner->capacity(1, 3, 0, 0);

		$this->assertSame(2, $capacity->slots);
		$this->assertSame(QueueCapacity::LIMIT_IN_FLIGHT, $capacity->limitedBy);
	}

	public function testAFullCapLeavesNoSlots(): void {
		$this->assertSame(0, $this->planner->capacity(2, 2, 0, 0)->slots);
	}

	public function testMoreInFlightThanTheCapIsZeroSlotsNotNegative(): void {
		// A lowered setting, or two pumps racing, can leave us over the cap. That
		// is "nothing more for now", never a negative count to carry forward.
		$this->assertSame(0, $this->planner->capacity(5, 2, 0, 0)->slots);
	}

	public function testACapBelowOneIsTreatedAsOne(): void {
		// A cap of 0 would stop the pipeline dead with nothing saying why. The
		// setter already clamps; this is belt and braces for a hand-edited value.
		$this->assertSame(1, $this->planner->capacity(0, 0, 0, 0)->slots);
	}

	public function testAnHourlyBudgetOfZeroMeansUnlimited(): void {
		$capacity = $this->planner->capacity(0, 2, 10000, 0);

		$this->assertSame(2, $capacity->slots);
		$this->assertSame(QueueCapacity::LIMIT_IN_FLIGHT, $capacity->limitedBy);
	}

	public function testTheHourlyBudgetBindsWhenItIsTheSmallerLimit(): void {
		$capacity = $this->planner->capacity(0, 5, 58, 60);

		$this->assertSame(2, $capacity->slots);
		$this->assertSame(QueueCapacity::LIMIT_HOURLY, $capacity->limitedBy);
	}

	public function testAnExhaustedBudgetStopsSchedulingEvenWithFreeSlots(): void {
		// The case the budget exists for: nothing is in flight, so the cap alone
		// would send the next message at once — straight into a rate limit.
		$capacity = $this->planner->capacity(0, 2, 60, 60);

		$this->assertSame(0, $capacity->slots);
		$this->assertSame(QueueCapacity::LIMIT_HOURLY, $capacity->limitedBy);
	}

	public function testOverspentBudgetIsZeroSlotsNotNegative(): void {
		// Retries and racing pumps can take the hour past its budget.
		$this->assertSame(0, $this->planner->capacity(0, 2, 75, 60)->slots);
	}

	public function testOnATieTheInFlightCapIsNamed(): void {
		// Both allow one more. Name the cap: it frees up soonest, so "waiting for
		// a free slot" is the more useful explanation of a wait.
		$capacity = $this->planner->capacity(1, 2, 59, 60);

		$this->assertSame(1, $capacity->slots);
		$this->assertSame(QueueCapacity::LIMIT_IN_FLIGHT, $capacity->limitedBy);
	}

	/* ---------------------------------------------------------------- pick */

	public function testNothingIsPickedWithoutSlots(): void {
		$this->assertSame([], $this->planner->pick(['alice' => [1, 2]], 0));
	}

	public function testNothingIsPickedFromAnEmptyQueue(): void {
		$this->assertSame([], $this->planner->pick([], 5));
		$this->assertSame([], $this->planner->pick(['alice' => []], 5));
	}

	public function testOneUserIsServedOldestFirst(): void {
		// Oldest first is a contract, not a preference: the first email about a
		// booking is the one that creates it.
		$picked = $this->planner->pick(['alice' => [3, 7, 9]], 2);

		$this->assertSame(['alice:3', 'alice:7'], $this->describe($picked));
	}

	public function testSlotsBeyondTheQueueAreSimplyUnused(): void {
		$picked = $this->planner->pick(['alice' => [3]], 5);

		$this->assertSame(['alice:3'], $this->describe($picked));
	}

	public function testUsersTakeTurnsSoABackfillCannotStarveNewMail(): void {
		// Alice is mid-backfill with a long queue; Bob has one new email. With a
		// plain oldest-first sort Bob would wait behind all of Alice's history.
		$picked = $this->planner->pick([
			'alice' => [1, 2, 3, 4, 5],
			'bob' => [40],
		], 3);

		$this->assertSame(['alice:1', 'bob:40', 'alice:2'], $this->describe($picked));
	}

	public function testTheLongestWaitingUserGoesFirst(): void {
		// Bob's oldest message predates Alice's, so Bob opens the round even though
		// the input lists Alice first.
		$picked = $this->planner->pick([
			'alice' => [10, 11],
			'bob' => [4, 12],
		], 3);

		$this->assertSame(['bob:4', 'alice:10', 'bob:12'], $this->describe($picked));
	}

	public function testAUserWhoRunsOutDropsOutOfLaterRounds(): void {
		$picked = $this->planner->pick([
			'alice' => [1],
			'bob' => [2, 3, 4],
		], 4);

		$this->assertSame(['alice:1', 'bob:2', 'bob:3', 'bob:4'], $this->describe($picked));
	}

	public function testNumericUserIdsSurviveBeingArrayKeys(): void {
		// PHP turns the key "1001" into the int 1001. LDAP user ids are often
		// numeric, and a string-typed comparator would throw under strict_types.
		$picked = $this->planner->pick(['1001' => [5], 'alice' => [6]], 2);

		$this->assertSame(['1001:5', 'alice:6'], $this->describe($picked));
		$this->assertSame('1001', $picked[0]->userId);
	}
}
