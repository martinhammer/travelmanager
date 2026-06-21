<?php

declare(strict_types=1);

namespace OCA\TravelManager\BackgroundJob;

use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\IngestionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Per-user ingestion job (decision 4). Runs once per dispatch, does the IMAP
 * fetch + dedup and enqueues extraction tasks for that single user. A failure
 * here is isolated to one user and does not affect the others.
 *
 * @psalm-suppress UnusedClass
 */
class UserIngestionJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ConfigService $configService,
		private IngestionService $ingestionService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run(mixed $argument): void {
		$userId = is_array($argument) ? ($argument['userId'] ?? null) : null;
		if (!is_string($userId) || $userId === '') {
			return;
		}

		// Re-check the flags: state may have changed since dispatch.
		if (!$this->configService->isFeatureEnabled() || !$this->configService->userExists($userId)) {
			return;
		}

		try {
			$enqueued = $this->ingestionService->ingestForUser($userId);
			if ($enqueued > 0) {
				$this->logger->info('Travel Manager: enqueued ' . $enqueued . ' message(s) for ' . $userId);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Travel Manager: ingestion failed for ' . $userId . ': ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
