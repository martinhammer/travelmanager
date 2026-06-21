<?php

declare(strict_types=1);

namespace OCA\TravelManager\BackgroundJob;

use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Single dispatcher job (decision 4). On each tick it enumerates enrolled users
 * and enqueues a per-user {@see UserIngestionJob}, isolating per-user failures
 * and spreading LLM load. Does no IMAP/LLM work itself.
 */
class DispatcherJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ConfigService $configService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Dispatcher cadence; per-user interval preferences are honoured by
		// the per-user job itself in a later step.
		$this->setInterval(15 * 60);
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		if (!$this->configService->isFeatureEnabled()) {
			return;
		}

		$userIds = $this->configService->getEnabledUserIds();
		foreach ($userIds as $userId) {
			if ($this->jobList->has(UserIngestionJob::class, ['userId' => $userId])) {
				continue;
			}
			$this->jobList->add(UserIngestionJob::class, ['userId' => $userId]);
		}

		if ($userIds !== []) {
			$this->logger->debug('Travel Manager: dispatched ingestion for ' . count($userIds) . ' user(s)');
		}
	}
}
