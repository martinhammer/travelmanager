<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use OCA\TravelManager\Exception\ImapException;
use OCP\Security\IRemoteHostValidator;

/**
 * Turns a mailbox host into the one address it is safe to dial.
 *
 * The host is a value any user can set, and cron dials it from the server, so
 * without this a mailbox setting reaches loopback, link-local and private
 * addresses — and the activity log hands back the operating system's error,
 * which is enough to map them. Nextcloud's rule for that is
 * `IRemoteHostValidator`, which honours the admin's `allow_local_remote_servers`
 * (Mail applies the same rule to IMAP servers), but it classifies the string it
 * is given and does no DNS of its own. So every address the name resolves to is
 * checked here, and the caller then dials the *checked address*, never the
 * name: resolving twice would let a short-lived DNS answer pass the check as a
 * public address and connect as 127.0.0.1. TLS still verifies against the name,
 * which the caller passes as `peer_name`.
 */
class ImapEndpointResolver {
	public function __construct(
		private IRemoteHostValidator $remoteHostValidator,
	) {
	}

	/**
	 * The address to dial for this host, in the form `host:port` needs.
	 *
	 * @throws ImapException when the name does not resolve, or any address it
	 *                       resolves to is one this server may not reach
	 */
	public function resolve(string $host): string {
		$literal = ImapEndpoint::ipLiteral($host);
		$addresses = $literal !== null ? [$literal] : $this->lookup($host);
		if ($addresses === []) {
			throw new ImapException('Could not resolve the IMAP host ' . $host . '.');
		}
		// The name too: the validator also refuses local names (localhost,
		// *.local) that may resolve to nothing at all here.
		foreach ([$host, ...$addresses] as $candidate) {
			if (!$this->remoteHostValidator->isValid($candidate)) {
				throw new ImapException(self::localMessage($host));
			}
		}
		// Every address passed, so any of them would do. IPv4 first: without
		// Happy Eyeballs a dead IPv6 route is a timeout, not a fallback.
		foreach ($addresses as $address) {
			if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
				return $address;
			}
		}
		return ImapEndpoint::dialable($addresses[0]);
	}

	/**
	 * Whether a host may be saved: the parts that need no DNS. A name is fully
	 * checked when it is dialled, since what it resolves to can change after
	 * saving, and a DNS hiccup should not stop someone saving their password.
	 */
	public function isAllowedBeforeLookup(string $host): bool {
		return $this->remoteHostValidator->isValid($host);
	}

	public static function localMessage(string $host): string {
		return 'The IMAP host ' . $host . ' is a local or private address. Nextcloud connects to those only when the administrator sets \'allow_local_remote_servers\' to true in config.php.';
	}

	/**
	 * Every IPv4 and IPv6 address the name resolves to. `gethostbynamel` goes
	 * through the system resolver, so /etc/hosts and container names work;
	 * `dns_get_record` adds the AAAA records it cannot return.
	 *
	 * @return list<string>
	 */
	protected function lookup(string $host): array {
		$v4 = gethostbynamel($host);
		$addresses = $v4 === false ? [] : $v4;
		$records = @dns_get_record($host, DNS_AAAA);
		if (is_array($records)) {
			foreach ($records as $record) {
				if (isset($record['ipv6']) && is_string($record['ipv6'])) {
					$addresses[] = $record['ipv6'];
				}
			}
		}
		return array_values(array_unique($addresses));
	}
}
