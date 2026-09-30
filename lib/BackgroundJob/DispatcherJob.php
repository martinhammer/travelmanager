<?php

declare(strict_types=1);

namespace OCA\TravelManager\BackgroundJob;

use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\ExtractionQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Single dispatcher job (decision 4). On each tick it settles extraction tasks
 * that went missing, sends whatever the queue's limits now allow, then enumerates
 * enrolled users and enqueues a per-user {@see UserIngestionJob}, isolating
 * per-user failures. Does no IMAP work itself.
 *
 * The tick is the queue's only clock. Task completions keep a backlog moving
 * while work is in flight, but when the hourly budget runs out nothing completes
 * — so this is what resumes sending once the hour rolls on.
 */
class DispatcherJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ConfigService $configService,
		private IJobList $jobList,
		private ExtractionQueue $queue,
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

		// Reconcile first: a settled lost task frees the slot the pump can then use.
		// Each guarded on its own, so a queue problem never stops mailbox reads.
		try {
			$this->queue->reconcile();
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: reconciling extraction tasks failed: ' . $e->getMessage(), ['exception' => $e]);
		}
		try {
			$this->queue->pump();
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: sending queued messages failed: ' . $e->getMessage(), ['exception' => $e]);
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
