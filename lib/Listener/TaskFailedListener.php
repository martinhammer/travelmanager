<?php

declare(strict_types=1);

namespace OCA\TravelManager\Listener;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Service\ExtractionResultHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskFailedEvent;

/**
 * Records Task Processing failures against the source message (V5).
 *
 * @implements IEventListener<TaskFailedEvent>
 * @psalm-suppress UnusedClass
 */
class TaskFailedListener implements IEventListener {
	public function __construct(
		private ExtractionResultHandler $handler,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof TaskFailedEvent)) {
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
		$this->handler->handleFailure($taskId, $event->getErrorMessage());
	}
}
