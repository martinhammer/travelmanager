<?php

declare(strict_types=1);

namespace Imap;

use OCA\TravelManager\Exception\ImapException;
use OCA\TravelManager\Imap\ImapEndpointResolver;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\TestCase;

final class ImapEndpointResolverTest extends TestCase {
	/** Addresses this test's validator treats as local, as the server's would. */
	private const LOCAL = ['127.0.0.1', '10.0.0.5', '::1', 'localhost', 'printer.local'];

	/**
	 * @param array<string, list<string>> $dns name => addresses
	 */
	private function resolver(array $dns): ImapEndpointResolver {
		$validator = $this->createMock(IRemoteHostValidator::class);
		$validator->method('isValid')->willReturnCallback(
			static fn (string $host): bool => !in_array($host, self::LOCAL, true),
		);
		return new class($validator, $dns) extends ImapEndpointResolver {
			/**
			 * @param array<string, list<string>> $dns
			 */
			public function __construct(
				IRemoteHostValidator $validator,
				private array $dns,
			) {
				parent::__construct($validator);
			}

			protected function lookup(string $host): array {
				return $this->dns[$host] ?? [];
			}
		};
	}

	public function testDialsTheAddressItChecked(): void {
		// The checked address, never the name: resolving again at connect time
		// would let a second DNS answer skip the check.
		$address = $this->resolver(['imap.example.com' => ['203.0.113.7']])->resolve('imap.example.com');
		$this->assertSame('203.0.113.7', $address);
	}

	public function testRefusesANameThatResolvesToAPrivateAddress(): void {
		$this->expectException(ImapException::class);
		$this->expectExceptionMessage('allow_local_remote_servers');
		$this->resolver(['intranet.example.com' => ['10.0.0.5']])->resolve('intranet.example.com');
	}

	public function testRefusesWhenAnyOfSeveralAddressesIsLocal(): void {
		// One public record must not vouch for a local one beside it.
		$this->expectException(ImapException::class);
		$this->resolver(['mixed.example.com' => ['203.0.113.7', '127.0.0.1']])->resolve('mixed.example.com');
	}

	public function testRefusesALocalIpLiteralWithoutALookup(): void {
		$this->expectException(ImapException::class);
		$this->resolver([])->resolve('127.0.0.1');
	}

	public function testRefusesALocalNameEvenWhenItDoesNotResolve(): void {
		$this->expectException(ImapException::class);
		$this->expectExceptionMessage('local or private');
		$this->resolver(['printer.local' => ['203.0.113.9']])->resolve('printer.local');
	}

	public function testReportsANameThatDoesNotResolve(): void {
		$this->expectException(ImapException::class);
		$this->expectExceptionMessage('Could not resolve');
		$this->resolver([])->resolve('nowhere.example.com');
	}

	public function testPrefersIpv4AndBracketsAnIpv6Fallback(): void {
		$resolver = $this->resolver([
			'dual.example.com' => ['2001:db8::1', '203.0.113.7'],
			'v6.example.com' => ['2001:db8::1'],
		]);
		$this->assertSame('203.0.113.7', $resolver->resolve('dual.example.com'));
		$this->assertSame('[2001:db8::1]', $resolver->resolve('v6.example.com'));
	}

	public function testABracketedIpv6LiteralIsDialledBracketed(): void {
		$this->assertSame('[2001:db8::1]', $this->resolver([])->resolve('[2001:db8::1]'));
	}
}
