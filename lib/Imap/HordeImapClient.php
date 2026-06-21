<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use Horde_Imap_Client;
use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Ids;
use Horde_Imap_Client_Search_Query;
use Horde_Imap_Client_Socket;
use OCA\TravelManager\Exception\ImapException;
use Psr\Log\LoggerInterface;

/**
 * Read-only IMAP consumer backed by Horde_Imap_Client (the same library the
 * Mail app uses, bundled here as a back-end-only dependency — see V3).
 *
 * The mailbox is opened in EXAMINE (read-only) mode and body parts are fetched
 * with `peek` so the \Seen flag is never set: the app never modifies the
 * mailbox (V6). Dedup is the caller's responsibility (app DB, by Message-ID).
 *
 * This class is a thin adapter over an untyped third-party library, hence the
 * blanket mixed-type suppressions.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedMethodCall
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress MixedInferredReturnType
 * @psalm-suppress MixedPropertyFetch
 * @psalm-suppress ArgumentTypeCoercion
 * @psalm-suppress PossiblyInvalidCast
 */
class HordeImapClient implements IImapClient {
	/** Hard cap on body text handed to the LLM, in characters. */
	private const MAX_BODY_LENGTH = 20000;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function verify(ImapConnection $connection): void {
		$client = $this->createSocket($connection);
		try {
			$client->login();
			// Opening read-only confirms the mailbox exists and is selectable.
			$client->openMailbox($connection->mailbox, Horde_Imap_Client::OPEN_READONLY);
		} catch (Horde_Imap_Client_Exception $e) {
			throw new ImapException('IMAP verification failed: ' . $e->getMessage(), 0, $e);
		} finally {
			$this->logout($client);
		}
	}

	public function fetchRecent(ImapConnection $connection, int $limit): array {
		if ($limit < 1) {
			return [];
		}
		$client = $this->createSocket($connection);
		$mailbox = $connection->mailbox;
		try {
			$client->openMailbox($mailbox, Horde_Imap_Client::OPEN_READONLY);

			$status = $client->status($mailbox, Horde_Imap_Client::STATUS_UIDVALIDITY);
			$uidValidity = (int)($status['uidvalidity'] ?? 0);

			// All messages, newest first by arrival.
			$searchResult = $client->search($mailbox, new Horde_Imap_Client_Search_Query(), [
				'sort' => [Horde_Imap_Client::SORT_REVERSE, Horde_Imap_Client::SORT_ARRIVAL],
			]);
			$uids = $this->idsToArray($searchResult['match'] ?? null);
			$uids = array_slice($uids, 0, $limit);
			if ($uids === []) {
				return [];
			}

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->uid();
			$query->envelope();
			$query->structure();
			$fetched = $client->fetch($mailbox, $query, ['ids' => new Horde_Imap_Client_Ids($uids)]);

			$messages = [];
			foreach ($fetched as $data) {
				$messages[] = $this->buildMessage($client, $mailbox, $data, $uidValidity);
			}
			return $messages;
		} catch (Horde_Imap_Client_Exception $e) {
			throw new ImapException('IMAP fetch failed: ' . $e->getMessage(), 0, $e);
		} finally {
			$this->logout($client);
		}
	}

	private function createSocket(ImapConnection $connection): Horde_Imap_Client_Socket {
		$secure = match (strtolower($connection->security)) {
			'ssl' => 'ssl',
			'tls' => 'tls',
			default => false,
		};
		return new Horde_Imap_Client_Socket([
			'username' => $connection->user,
			'password' => $connection->password,
			'hostspec' => $connection->host,
			'port' => $connection->port,
			'secure' => $secure,
			'timeout' => 20,
		]);
	}

	private function logout(Horde_Imap_Client_Socket $client): void {
		try {
			$client->logout();
		} catch (\Throwable $e) {
			$this->logger->debug('Travel Manager: IMAP logout failed: ' . $e->getMessage());
		}
	}

	private function buildMessage(
		Horde_Imap_Client_Socket $client,
		string $mailbox,
		mixed $data,
		int $uidValidity,
	): ImapMessage {
		$envelope = $data->getEnvelope();
		$uid = (int)$data->getUid();

		$messageId = trim((string)$envelope->message_id);
		if ($messageId === '') {
			// Synthetic but stable id so dedup still works without a Message-ID.
			$messageId = sprintf('<tm-%d-%d@travelmanager.local>', $uidValidity, $uid);
		}

		$subject = (string)$envelope->subject;
		$date = $this->toDate($envelope->date);
		$body = $this->extractText($client, $mailbox, $data);

		return new ImapMessage($messageId, $uid, $uidValidity, $subject, $date, $body);
	}

	/**
	 * Fetch and decode the best text body (plain preferred, HTML stripped as a
	 * fallback), normalised to UTF-8 and capped in length.
	 */
	private function extractText(Horde_Imap_Client_Socket $client, string $mailbox, mixed $data): string {
		$structure = $data->getStructure();

		$isHtml = false;
		$partId = $structure->findBody('plain');
		if ($partId === null) {
			$partId = $structure->findBody('html');
			$isHtml = $partId !== null;
		}
		if ($partId === null) {
			return '';
		}

		$query = new Horde_Imap_Client_Fetch_Query();
		// `peek` => do NOT set the \Seen flag (read-only, V6).
		$query->bodyPart($partId, ['peek' => true]);
		$result = $client->fetch($mailbox, $query, ['ids' => new Horde_Imap_Client_Ids([$data->getUid()])]);

		$bodyData = null;
		foreach ($result as $row) {
			$bodyData = $row;
			break;
		}
		if ($bodyData === null) {
			return '';
		}

		$part = $structure->getPart($partId);
		$part->setContents(
			$bodyData->getBodyPart($partId),
			['encoding' => $bodyData->getBodyPartDecode($partId)],
		);
		$decoded = (string)$part->getContents();
		$text = $this->toUtf8($decoded, (string)$part->getCharset());

		if ($isHtml) {
			$text = Html::toText($text);
		}
		return $this->normalise($text);
	}

	private function toUtf8(string $text, string $charset): string {
		$charset = strtoupper(trim($charset));
		if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII' || $charset === 'ASCII') {
			return $text;
		}
		$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
		return $converted === false ? $text : $converted;
	}

	private function normalise(string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		$text = trim($text);
		if (strlen($text) > self::MAX_BODY_LENGTH) {
			$text = substr($text, 0, self::MAX_BODY_LENGTH);
		}
		return $text;
	}

	private function toDate(mixed $value): ?\DateTimeImmutable {
		if ($value instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($value);
		}
		$string = is_object($value) || is_string($value) ? trim((string)$value) : '';
		if ($string === '') {
			return null;
		}
		try {
			return new \DateTimeImmutable($string);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return list<int>
	 */
	private function idsToArray(mixed $ids): array {
		if ($ids === null) {
			return [];
		}
		$out = [];
		foreach ($ids as $id) {
			$out[] = (int)$id;
		}
		return $out;
	}
}
