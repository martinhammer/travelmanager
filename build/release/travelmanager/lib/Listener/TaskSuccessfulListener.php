<?php

declare(strict_types=1);

namespace OCA\TravelManager\Listener;

use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Service\ExtractionResultHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

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
	}
}
