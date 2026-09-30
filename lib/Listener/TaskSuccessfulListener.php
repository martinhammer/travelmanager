<?php

declare(strict_types=1);

namespace OCA\TravelManager\Listener;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Service\ConfigService;
use OCA\TravelManager\Service\ExtractionQueue;
use OCA\TravelManager\Service\ExtractionResultHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use Psr\Log\LoggerInterface;

/**
 * Receives completed Task Processing tasks (V5). Only acts on tasks scheduled
 * by this app; correlation back to the source message happens in the handler.
 *
 * @implements IEventListener<TaskSuccessfulEvent>
 * @psalm-suppress UnusedClass
 */
class TaskSuccessfulListener implements IEventListener {
	public function __construct(
		private ExtractionResultHandler $handler,
		private ExtractionQueue $queue,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof TaskSuccessfulEvent)) {
			return;
		}
		$task = $event->getTask();
		if ($task->getAppId() !== Application::APP_ID) {
			return;
		}
		$taskId = $task->getId();
		if ($taskId === null) {
			return;
		}
		$this->handler->handleSuccess($taskId, $task->getOutput() ?? []);
		$this->topUp();
	}

	/**
	 * A finished task frees a slot, so this is when the next queued message
	 * should go — not up to fifteen minutes later on the dispatcher's tick. Gated
	 * on the feature flag like all automatic work; never allowed to throw, since
	 * the result above is already recorded and a pump failure must not undo that
	 * in the caller's eyes.
	 */
	private function topUp(): void {
		if (!$this->configService->isFeatureEnabled()) {
			return;
		}
		try {
			$this->queue->pump();
		} catch (\Throwable $e) {
			$this->logger->warning('Travel Manager: could not send queued messages: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
