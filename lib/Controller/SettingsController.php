<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Exception\ImapException;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapConnection;
use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Reads/writes the current user's Travel Manager settings. The IMAP password is
 * write-only over the API (stored via ICredentialsManager, never returned).
 *
 * @psalm-suppress UnusedClass
 */
class SettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private IImapClient $imapClient,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/settings')]
	public function show(): DataResponse {
		return new DataResponse($this->configService->getUserSettings($this->uid())->jsonSerialize());
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/settings')]
	public function update(
		?bool $enabled = null,
		?string $imapHost = null,
		?int $imapPort = null,
		?string $imapSecurity = null,
		?string $imapUser = null,
		?string $mailbox = null,
		?int $intervalMinutes = null,
		?string $imapPassword = null,
	): DataResponse {
		$uid = $this->uid();
		$values = array_filter([
			'imapHost' => $imapHost,
			'imapPort' => $imapPort,
			'imapSecurity' => $imapSecurity,
			'imapUser' => $imapUser,
			'mailbox' => $mailbox,
			'intervalMinutes' => $intervalMinutes,
		], static fn ($v) => $v !== null);
		$this->configService->setUserSettings($uid, $values);

		if ($imapPassword !== null && $imapPassword !== '') {
			$this->configService->setImapPassword($uid, $imapPassword);
		}
		if ($enabled !== null) {
			$this->configService->setUserEnabled($uid, $enabled);
		}

		return new DataResponse($this->configService->getUserSettings($uid)->jsonSerialize());
	}

	/**
	 * Verify the stored credentials can open the mailbox. No-op until the
	 * Horde-backed IMAP client replaces the scaffold stub.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/settings/test')]
	public function test(): DataResponse {
		$uid = $this->uid();
		$settings = $this->configService->getUserSettings($uid);
		$password = $this->configService->getImapPassword($uid);
		if ($password === null) {
			return new DataResponse(['ok' => false, 'error' => 'No password stored']);
		}
		$connection = new ImapConnection(
			$settings->imapHost,
			$settings->imapPort,
			$settings->imapSecurity,
			$settings->imapUser,
			$password,
			$settings->mailbox,
		);
		try {
			$this->imapClient->verify($connection);
			return new DataResponse(['ok' => true]);
		} catch (ImapException $e) {
			return new DataResponse(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
