<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

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
	//
	// NOT 'enabled': that key is reserved by the server, which records whether an
	// app is enabled at `oc_appconfig(<app>, 'enabled')` as the string 'yes'/'no'.
	// Writing our own flag there rewrote both the value and its declared type, and
	// core reads every app's `enabled` as a string from
	// `OC\AppConfig::getAppInstalledVersions()` — which runs during bootstrap, via
	// Memcache\Factory::getGlobalPrefix() building OC\User\Manager. A bool-typed
	// row therefore threw AppConfigTypeConflictException on *every* request, web
	// and occ alike, taking the whole instance down hard enough that the container
	// entrypoint mistook it for an uninstalled server. See
	// Version2500Date20260918000000 for the repair.
	public const APP_PIPELINE_ENABLED = 'pipeline_enabled';
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
		private LoggerInterface $logger,
	) {
	}

	/* ---------------------------------------------------------------- admin */

	/** Global feature flag — the whole pipeline is off unless this is true. */
	public function isFeatureEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::APP_PIPELINE_ENABLED, false);
	}

	public function setFeatureEnabled(bool $enabled): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::APP_PIPELINE_ENABLED, $enabled);
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

	/**
	 * The stored app password, or null when there is not a usable one.
	 *
	 * "Not usable" includes a credential that will not decrypt. `ICredentialsManager`
	 * decrypts with the instance's `secret`, so if that is ever regenerated — a
	 * botched restore, a re-run installer — every stored credential becomes
	 * undecryptable and `Crypto::decrypt` throws `HMAC does not match`. Left
	 * uncaught that throw reaches `PersonalSettings::getForm()` through
	 * `hasImapPassword()`, and the panel 500s: the one screen the user needs in
	 * order to re-enter the password is the one the unreadable password takes
	 * away. Treating it as absent is both honest — we have no password we can use
	 * — and the state the UI already knows how to offer a fix for, since saving
	 * overwrites the unreadable value.
	 *
	 * Deliberately *not* deleted here. A getter that writes is a trap for the next
	 * reader, and the row costs nothing while it waits to be overwritten.
	 */
	public function getImapPassword(string $userId): ?string {
		try {
			/** @var string|null $value */
			$value = $this->credentialsManager->retrieve($userId, self::CREDENTIAL_KEY);
		} catch (\Throwable $e) {
			// Warning, not error: nothing is broken that re-entering the password
			// will not fix. Logged at all because a whole instance losing its
			// `secret` is worth one line of explanation in the log — otherwise the
			// password merely appears to have unset itself.
			$this->logger->warning(
				'Travel Manager: stored IMAP password for ' . $userId
				. ' could not be decrypted and is being treated as unset'
				. ' (the instance secret may have changed); the user must re-enter it',
				['exception' => $e],
			);
			return null;
		}
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
