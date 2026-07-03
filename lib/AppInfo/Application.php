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
		// Load our bundled Composer autoloader for the read-only Horde IMAP
		// client. Nextcloud only auto-includes an app's `composer/autoload.php`
		// (see OC_App::registerAutoloading), but the build ships the autoloader
		// under `vendor/`, so Horde's PSR-0 classes (e.g. Horde_Imap_Client_Socket)
		// would otherwise be unknown at request time. require_once + Composer's
		// own static guard make this idempotent.
		$autoloader = __DIR__ . '/../../vendor/autoload.php';
		if (file_exists($autoloader)) {
			/** @psalm-suppress UnresolvableInclude */
			require_once $autoloader;
		}

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
