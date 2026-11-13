<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

/**
 * What a mailbox address may look like: the host, port and encryption a user
 * may save, and that a connection is checked against again before dialling.
 *
 * Pure and dependency-free, so it is tested without stubs. Whether a *valid*
 * host is one this server may reach (not loopback, not the LAN) is a separate
 * question that needs DNS and the server's policy — see ImapEndpointResolver.
 *
 * Every rule exists because Horde passes the host straight to
 * `stream_socket_client`: a `://` anywhere but offset 0 is not recognised as a
 * scheme, so `tcp://127.0.0.1` is dialled as written, and with port 0 Horde
 * drops the port and `unix:///path` reaches a local socket. Accepting only a
 * hostname or an IP literal closes all of that at once.
 */
final class ImapEndpoint {
	public const SECURITY_SSL = 'ssl';
	public const SECURITY_TLS = 'tls';
	public const SECURITY_NONE = 'none';
	public const SECURITY_MODES = [self::SECURITY_SSL, self::SECURITY_TLS, self::SECURITY_NONE];

	/** RFC 1035's limit on a full domain name, in its textual form. */
	private const MAX_HOST_LENGTH = 253;

	/**
	 * Why this host cannot be used, or null when it can.
	 */
	public static function hostProblem(string $host): ?string {
		if ($host === '') {
			return 'The IMAP host is empty.';
		}
		if (strlen($host) > self::MAX_HOST_LENGTH) {
			return 'The IMAP host is too long.';
		}
		if (self::ipLiteral($host) !== null) {
			return null;
		}
		// Unicode names are checked in their ASCII form, which is what DNS sees.
		$ascii = $host;
		if (preg_match('/[^\x00-\x7F]/', $host) === 1 && function_exists('idn_to_ascii')) {
			$ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			if ($ascii === false) {
				return 'The IMAP host is not a valid host name.';
			}
		}
		if (filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
			return 'The IMAP host must be a host name or an IP address, without a scheme, path or port (for example imap.example.com).';
		}
		return null;
	}

	/**
	 * Why this port cannot be used, or null when it can. Zero is refused in
	 * particular: Horde drops a zero port, which is what lets a `unix://` path
	 * through.
	 */
	public static function portProblem(int $port): ?string {
		return $port >= 1 && $port <= 65535 ? null : 'The IMAP port must be between 1 and 65535.';
	}

	/**
	 * Why this encryption setting cannot be used, or null when it can. Anything
	 * unrecognised used to be dialled in cleartext.
	 */
	public static function securityProblem(string $security): ?string {
		return in_array($security, self::SECURITY_MODES, true)
			? null
			: 'The IMAP encryption must be one of: ' . implode(', ', self::SECURITY_MODES) . '.';
	}

	/**
	 * The first problem with a whole endpoint, or null when it is usable.
	 */
	public static function problem(string $host, int $port, string $security): ?string {
		return self::hostProblem($host) ?? self::portProblem($port) ?? self::securityProblem($security);
	}

	/**
	 * The bare address when the host is an IP literal (an IPv6 one may come in
	 * brackets, as in a URL), or null when it is a name.
	 */
	public static function ipLiteral(string $host): ?string {
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			// Brackets are only meaningful around IPv6.
			$bare = substr($host, 1, -1);
			return filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $bare : null;
		}
		return filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
	}

	/**
	 * An address as `stream_socket_client` needs it in `host:port`: IPv6 in
	 * brackets, so its colons are not read as the port separator.
	 */
	public static function dialable(string $address): string {
		return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $address . ']' : $address;
	}
}
