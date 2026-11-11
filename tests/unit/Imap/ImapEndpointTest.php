<?php

declare(strict_types=1);

namespace Imap;

use OCA\TravelManager\Imap\ImapEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImapEndpointTest extends TestCase {
	/**
	 * @return array<string, array{string}>
	 */
	public static function acceptedHosts(): array {
		return [
			'name' => ['imap.example.com'],
			'single label' => ['mailserver'],
			'ipv4' => ['203.0.113.7'],
			'bare ipv6' => ['2001:db8::1'],
			'bracketed ipv6' => ['[2001:db8::1]'],
			'hyphen and digits' => ['mail-01.example.co.uk'],
		];
	}

	#[DataProvider('acceptedHosts')]
	public function testAcceptsAHostNameOrAnIpLiteral(string $host): void {
		$this->assertNull(ImapEndpoint::hostProblem($host));
	}

	/**
	 * Each of these reached `stream_socket_client` as written: Horde recognises a
	 * scheme only at offset 0, so anything with `://` is dialled verbatim.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refusedHosts(): array {
		return [
			'empty' => [''],
			'tcp scheme' => ['tcp://127.0.0.1'],
			'unix socket' => ['unix:///var/run/dovecot/socket'],
			'scheme mid-string' => ['x tcp://127.0.0.1'],
			'path' => ['imap.example.com/inbox'],
			'port suffix' => ['imap.example.com:993'],
			'whitespace' => ['imap example.com'],
			'user info' => ['user@imap.example.com'],
			'brackets around ipv4' => ['[127.0.0.1]'],
			// Every label is within 63, so only the total length can refuse it.
			'too long' => [implode('.', array_fill(0, 4, str_repeat('a', 63))) . '.com'],
		];
	}

	#[DataProvider('refusedHosts')]
	public function testRefusesAnythingThatIsNotAPlainHost(string $host): void {
		$this->assertNotNull(ImapEndpoint::hostProblem($host));
	}

	public function testPortMustBeInRangeAndNeverZero(): void {
		// Zero in particular: Horde drops a zero port, which is what let a
		// `unix://` host through.
		$this->assertNotNull(ImapEndpoint::portProblem(0));
		$this->assertNotNull(ImapEndpoint::portProblem(-1));
		$this->assertNotNull(ImapEndpoint::portProblem(65536));
		$this->assertNull(ImapEndpoint::portProblem(1));
		$this->assertNull(ImapEndpoint::portProblem(993));
		$this->assertNull(ImapEndpoint::portProblem(65535));
	}

	public function testOnlyTheThreeOfferedEncryptionModesAreAccepted(): void {
		foreach (['ssl', 'tls', 'none'] as $mode) {
			$this->assertNull(ImapEndpoint::securityProblem($mode));
		}
		// Anything else used to fall through to a cleartext connection.
		foreach (['', 'SSL ', 'starttls', 'true', 'sslv3'] as $mode) {
			$this->assertNotNull(ImapEndpoint::securityProblem($mode), $mode);
		}
	}

	public function testProblemReportsTheFirstFault(): void {
		$this->assertNull(ImapEndpoint::problem('imap.example.com', 993, 'ssl'));
		$this->assertStringContainsString('host', (string)ImapEndpoint::problem('tcp://x', 0, 'bogus'));
		$this->assertStringContainsString('port', (string)ImapEndpoint::problem('imap.example.com', 0, 'bogus'));
		$this->assertStringContainsString('encryption', (string)ImapEndpoint::problem('imap.example.com', 993, 'bogus'));
	}

	public function testIpLiteralStripsBracketsFromIpv6Only(): void {
		$this->assertSame('2001:db8::1', ImapEndpoint::ipLiteral('[2001:db8::1]'));
		$this->assertSame('2001:db8::1', ImapEndpoint::ipLiteral('2001:db8::1'));
		$this->assertSame('192.0.2.1', ImapEndpoint::ipLiteral('192.0.2.1'));
		$this->assertNull(ImapEndpoint::ipLiteral('[192.0.2.1]'));
		$this->assertNull(ImapEndpoint::ipLiteral('imap.example.com'));
	}

	public function testDialableBracketsIpv6SoItsColonsAreNotThePort(): void {
		$this->assertSame('[2001:db8::1]', ImapEndpoint::dialable('2001:db8::1'));
		$this->assertSame('192.0.2.1', ImapEndpoint::dialable('192.0.2.1'));
	}
}
