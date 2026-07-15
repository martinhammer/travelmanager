<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;

/**
 * Central access point for all app and per-user configuration, plus the
 * encrypted IMAP credential. Secrets only ever live in ICredentialsManager,
 * never in app/user config (see brief §6).
 *
 * Per-user values go through OCP\Config\IUserConfig (NC 33+ replaced the now
 * deprecated OCP\IConfig::*UserValue methods); values are stored as strings to
 * keep the wire-format stable. searchUsersByValueString() gives us the
 * enrolled-user fan-out.
 */
class ConfigService {
	private const CREDENTIAL_KEY = Application::APP_ID . '_imap_password';

	// App-level (admin) keys.
	public const APP_ENABLED = 'enabled';
	public const APP_RATE_LIMIT_PER_RUN = 'rate_limit_per_run';
	public const APP_LOCAL_CONCURRENCY = 'local_concurrency';

	// Per-user keys.
	public const USER_ENABLED = 'enabled';
	public const USER_IMAP_HOST = 'imap_host';
	public const USER_IMAP_PORT = 'imap_port';
	public const USER_IMAP_SECURITY = 'imap_security';
	public const USER_IMAP_USER = 'imap_user';
	public const USER_MAILBOX = 'mailbox';
	public const USER_INTERVAL = 'interval_minutes';

	public const DEFAULT_INTERVAL_MINUTES = 15;

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IUserManager $userManager,
		private ICredentialsManager $credentialsManager,
	) {
	}

	/* ---------------------------------------------------------------- admin */

	/** Global feature flag — the whole pipeline is off unless this is true. */
	public function isFeatureEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::APP_ENABLED, false);
	}

	public function setFeatureEnabled(bool $enabled): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::APP_ENABLED, $enabled);
	}

	/** Max messages to enqueue per user per run (throttle external/local load). */
	public function getRateLimitPerRun(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::APP_RATE_LIMIT_PER_RUN, 20);
	}

	public function setRateLimitPerRun(int $value): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::APP_RATE_LIMIT_PER_RUN, max(1, $value));
	}

	public function getLocalConcurrency(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::APP_LOCAL_CONCURRENCY, 1);
	}

	public function setLocalConcurrency(int $value): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::APP_LOCAL_CONCURRENCY, max(1, $value));
	}

	/* ----------------------------------------------------------------- user */

	public function getUserSettings(string $userId): UserSettings {
		return new UserSettings(
			$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_ENABLED, '0') === '1',
			$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_IMAP_HOST, ''),
			(int)$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_IMAP_PORT, '993'),
			$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_IMAP_SECURITY, 'ssl'),
			$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_IMAP_USER, ''),
			$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_MAILBOX, 'INBOX'),
			(int)$this->userConfig->getValueString($userId, Application::APP_ID, self::USER_INTERVAL, (string)self::DEFAULT_INTERVAL_MINUTES),
			$this->hasImapPassword($userId),
		);
	}

	public function setUserEnabled(string $userId, bool $enabled): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_ENABLED, $enabled ? '1' : '0');
	}

	/**
	 * Persist the non-secret IMAP/account settings for a user.
	 *
	 * @param array<string, mixed> $values
	 */
	public function setUserSettings(string $userId, array $values): void {
		if (isset($values['imapHost'])) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_IMAP_HOST, (string)$values['imapHost']);
		}
		if (isset($values['imapPort'])) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_IMAP_PORT, (string)(int)$values['imapPort']);
		}
		if (isset($values['imapSecurity'])) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_IMAP_SECURITY, (string)$values['imapSecurity']);
		}
		if (isset($values['imapUser'])) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_IMAP_USER, (string)$values['imapUser']);
		}
		if (isset($values['mailbox'])) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_MAILBOX, (string)$values['mailbox']);
		}
		if (isset($values['intervalMinutes'])) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_INTERVAL, (string)max(5, (int)$values['intervalMinutes']));
		}
	}

	/**
	 * Enumerate the users who have enabled Travel Manager. Used by the
	 * dispatcher to fan out per-user jobs.
	 *
	 * @return string[] user ids
	 */
	public function getEnabledUserIds(): array {
		return iterator_to_array(
			$this->userConfig->searchUsersByValueString(Application::APP_ID, self::USER_ENABLED, '1'),
			false,
		);
	}

	/* ----------------------------------------------------------- credential */

	public function setImapPassword(string $userId, string $password): void {
		$this->credentialsManager->store($userId, self::CREDENTIAL_KEY, $password);
	}

	public function getImapPassword(string $userId): ?string {
		/** @var string|null $value */
		$value = $this->credentialsManager->retrieve($userId, self::CREDENTIAL_KEY);
		return ($value === null || $value === '') ? null : $value;
	}

	public function hasImapPassword(string $userId): bool {
		return $this->getImapPassword($userId) !== null;
	}

	public function deleteImapPassword(string $userId): void {
		$this->credentialsManager->delete($userId, self::CREDENTIAL_KEY);
	}

	public function userExists(string $userId): bool {
		return $this->userManager->userExists($userId);
	}
}
