<?php

declare(strict_types=1);

namespace OCA\TravelManager\AppInfo;

use OCA\TravelManager\Imap\HordeImapClient;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Listener\TaskFailedListener;
use OCA\TravelManager\Listener\TaskSuccessfulListener;
use OCA\TravelManager\Llm\ILlmService;
use OCA\TravelManager\Llm\TaskProcessingLlmService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'travelmanager';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// LLM access seam — single platform strategy for the MVP (V2).
		$context->registerServiceAlias(ILlmService::class, TaskProcessingLlmService::class);

		// IMAP seam — read-only Horde-backed client (V3).
		$context->registerServiceAlias(IImapClient::class, HordeImapClient::class);

		// Task Processing result delivery (V5).
		$context->registerEventListener(TaskSuccessfulEvent::class, TaskSuccessfulListener::class);
		$context->registerEventListener(TaskFailedEvent::class, TaskFailedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
