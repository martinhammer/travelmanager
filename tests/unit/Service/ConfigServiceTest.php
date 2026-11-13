<?php

declare(strict_types=1);

namespace Service;

use OCA\TravelManager\Imap\ImapEndpointResolver;
use OCA\TravelManager\Service\ConfigService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConfigServiceTest extends TestCase {
	private IUserConfig&MockObject $userConfig;
	private ConfigService $service;

	protected function setUp(): void {
		$this->userConfig = $this->createMock(IUserConfig::class);
		$validator = $this->createMock(IRemoteHostValidator::class);
		$validator->method('isValid')->willReturnCallback(
			static fn (string $host): bool => !in_array($host, ['127.0.0.1', 'localhost'], true),
		);
		$this->service = new ConfigService(
			$this->createMock(IAppConfig::class),
			$this->userConfig,
			$this->createMock(IUserManager::class),
			$this->createMock(ICredentialsManager::class),
			$this->createMock(LoggerInterface::class),
			new ImapEndpointResolver($validator),
		);
	}

	public function testSavesAValidMailboxAddressNormalised(): void {
		$written = [];
		$this->userConfig->method('setValueString')->willReturnCallback(
			function (string $userId, string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			},
		);
		$this->service->setUserSettings('alice', [
			'imapHost' => '  imap.example.com ',
			'imapPort' => 993,
			'imapSecurity' => 'SSL',
		]);
		$this->assertSame('imap.example.com', $written[ConfigService::USER_IMAP_HOST]);
		$this->assertSame('993', $written[ConfigService::USER_IMAP_PORT]);
		$this->assertSame('ssl', $written[ConfigService::USER_IMAP_SECURITY]);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function refusedSettings(): array {
		return [
			'scheme in host' => [['imapHost' => 'tcp://127.0.0.1', 'imapPort' => 993, 'imapSecurity' => 'ssl']],
			'port zero' => [['imapHost' => 'imap.example.com', 'imapPort' => 0, 'imapSecurity' => 'ssl']],
			'unknown encryption' => [['imapHost' => 'imap.example.com', 'imapPort' => 993, 'imapSecurity' => 'plain']],
			'local literal' => [['imapHost' => '127.0.0.1', 'imapPort' => 993, 'imapSecurity' => 'ssl']],
			'local name' => [['imapHost' => 'localhost', 'imapPort' => 143, 'imapSecurity' => 'tls']],
			// Every stored message records it, so every one would fail to store.
			'over-long mailbox' => [['imapHost' => 'imap.example.com', 'imapPort' => 993, 'imapSecurity' => 'ssl', 'mailbox' => str_repeat('m', 256)]],
		];
	}

	/**
	 * @param array<string, mixed> $values
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusedSettings')]
	public function testARefusedSaveWritesNothing(array $values): void {
		$this->userConfig->expects($this->never())->method('setValueString');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setUserSettings('alice', $values + ['imapUser' => 'alice', 'mailbox' => 'INBOX']);
	}

	public function testAnEmptyHostMayBeSavedAsUnconfigured(): void {
		$this->userConfig->expects($this->atLeastOnce())->method('setValueString');
		$this->service->setUserSettings('alice', ['imapHost' => '', 'imapPort' => 993, 'imapSecurity' => 'ssl']);
	}
}
