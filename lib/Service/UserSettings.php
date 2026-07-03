<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

/**
 * Immutable view of a single user's Travel Manager configuration.
 * The IMAP password is NOT carried here; it is read on demand from
 * ICredentialsManager by the ingestion layer.
 *
 * @psalm-import-type TravelManagerUserSettings from \OCA\TravelManager\ResponseDefinitions
 */
class UserSettings implements \JsonSerializable {
	public function __construct(
		public readonly bool $enabled,
		public readonly string $imapHost,
		public readonly int $imapPort,
		public readonly string $imapSecurity,
		public readonly string $imapUser,
		public readonly string $mailbox,
		public readonly int $intervalMinutes,
		public readonly bool $hasPassword,
	) {
	}

	/**
	 * Whether we have everything needed to open the IMAP connection. Does NOT
	 * consider the per-user enabled flag, so the manual "read mailbox now" dev
	 * trigger can run before the pipeline is switched on.
	 */
	public function isConnectable(): bool {
		return $this->imapHost !== ''
			&& $this->imapUser !== ''
			&& $this->mailbox !== ''
			&& $this->hasPassword;
	}

	/** Whether the background pipeline should process this user: connectable AND enabled. */
	public function isConfigured(): bool {
		return $this->enabled && $this->isConnectable();
	}

	/**
	 * @return TravelManagerUserSettings
	 */
	public function jsonSerialize(): array {
		return [
			'enabled' => $this->enabled,
			'imapHost' => $this->imapHost,
			'imapPort' => $this->imapPort,
			'imapSecurity' => $this->imapSecurity,
			'imapUser' => $this->imapUser,
			'mailbox' => $this->mailbox,
			'intervalMinutes' => $this->intervalMinutes,
			'hasPassword' => $this->hasPassword,
			'isConfigured' => $this->isConfigured(),
		];
	}
}
