<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Exception\ImapException;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapConnection;
use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Http;
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
 * @psalm-import-type TravelManagerUserSettings from \OCA\TravelManager\ResponseDefinitions
 * @psalm-import-type TravelManagerConnectionTest from \OCA\TravelManager\ResponseDefinitions
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

	/**
	 * Get the current user's Travel Manager settings
	 *
	 * @return DataResponse<Http::STATUS_OK, TravelManagerUserSettings, array{}>
	 *
	 * 200: Settings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/settings')]
	public function show(): DataResponse {
		return new DataResponse($this->configService->getUserSettings($this->uid())->jsonSerialize());
	}

	/**
	 * Update the current user's Travel Manager settings
	 *
	 * @param bool|null $enabled Whether automatic extraction is enabled for this user
	 * @param string|null $imapHost IMAP host
	 * @param int|null $imapPort IMAP port
	 * @param string|null $imapSecurity Connection encryption (ssl, tls or none)
	 * @param string|null $imapUser IMAP account / username
	 * @param string|null $mailbox Mailbox / folder to read
	 * @param int|null $intervalMinutes Check interval in minutes
	 * @param string|null $imapPassword IMAP app password (write-only; blank keeps the current one)
	 * @return DataResponse<Http::STATUS_OK, TravelManagerUserSettings, array{}>
	 *
	 * 200: Updated settings returned
	 */
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
		], static fn ($v): bool => $v !== null);
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
	 * Test the stored IMAP credentials against the configured mailbox
	 *
	 * @return DataResponse<Http::STATUS_OK, TravelManagerConnectionTest, array{}>
	 *
	 * 200: Connection test result returned
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
			return new DataResponse(['ok' => true, 'error' => '']);
		} catch (ImapException $e) {
			return new DataResponse(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
