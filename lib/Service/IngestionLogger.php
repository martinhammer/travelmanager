<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Db\IngestionLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Writes a per-user, step-by-step record of the ingestion/extraction pipeline
 * to the database so a run can be inspected from the UI (developer/debugging
 * aid). Also mirrors each entry to the server log.
 *
 * Logging must never break ingestion: every write is best-effort and swallows
 * its own failures.
 */
class IngestionLogger {
	/**
	 * Long troubleshooting context (prompt + raw LLM response + task metadata)
	 * is truncated before storage so a single row can't grow unbounded, but the
	 * cap is generous enough to keep the whole prompt and response for debugging.
	 */
	private const MAX_CONTEXT = 20000;

	public function __construct(
		private IngestionLogMapper $mapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function info(string $userId, string $step, string $message, ?string $context = null): void {
		$this->log($userId, IngestionLog::LEVEL_INFO, $step, $message, $context);
	}

	public function warning(string $userId, string $step, string $message, ?string $context = null): void {
		$this->log($userId, IngestionLog::LEVEL_WARNING, $step, $message, $context);
	}

	public function error(string $userId, string $step, string $message, ?string $context = null): void {
		$this->log($userId, IngestionLog::LEVEL_ERROR, $step, $message, $context);
	}

	public function log(string $userId, string $level, string $step, string $message, ?string $context = null): void {
		try {
			$entry = new IngestionLog();
			$entry->setUserId($userId);
			$entry->setLevel($level);
			$entry->setStep($step);
			$entry->setMessage($message);
			$entry->setContext($this->truncate($context));
			$entry->setCreatedAt($this->timeFactory->getDateTime());
			$this->mapper->insert($entry);
			$this->mapper->pruneForUser($userId);
		} catch (\Throwable $e) {
			// Never let the activity log interfere with the pipeline itself.
			$this->logger->warning('Travel Manager: could not write activity log: ' . $e->getMessage());
		}

		$line = 'Travel Manager [' . $step . '] ' . $message;
		match ($level) {
			IngestionLog::LEVEL_ERROR => $this->logger->error($line),
			IngestionLog::LEVEL_WARNING => $this->logger->warning($line),
			default => $this->logger->info($line),
		};
	}

	public function clear(string $userId): void {
		$this->mapper->deleteAllForUser($userId);
	}

	private function truncate(?string $context): ?string {
		if ($context === null) {
			return null;
		}
		if (mb_strlen($context) <= self::MAX_CONTEXT) {
			return $context;
		}
		return mb_substr($context, 0, self::MAX_CONTEXT) . "\n… (truncated)";
	}
}
