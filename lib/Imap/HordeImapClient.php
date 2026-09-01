<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use Horde_Imap_Client;
use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Ids;
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
			throw new ImapException('IMAP verification failed: ' . $this->describe($e), 0, $e);
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

			$status = $client->status(
				$mailbox,
				Horde_Imap_Client::STATUS_UIDVALIDITY | Horde_Imap_Client::STATUS_MESSAGES,
			);
			$uidValidity = (int)($status['uidvalidity'] ?? 0);
			$total = (int)($status['messages'] ?? 0);
			if ($total === 0) {
				return [];
			}

			// Take the last $limit messages by sequence number (new mail arrives
			// last). This deliberately avoids server-side SEARCH/SORT, which not
			// every IMAP server supports (some reject `UID SORT … ALL` with
			// "Illegal arguments"). The window is the newest $limit; the order
			// within it is oldest-first — see the return below.
			$start = max(1, $total - $limit + 1);
			$ids = new Horde_Imap_Client_Ids($start . ':' . $total, true);

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->uid();
			$query->envelope();
			$query->structure();
			$fetched = $client->fetch($mailbox, $query, ['ids' => $ids]);

			$messages = [];
			foreach ($fetched as $data) {
				$messages[] = $this->buildMessage($client, $mailbox, $data, $uidValidity);
			}
			// Oldest first, by UID rather than by the order the server happened to
			// send the untagged FETCH responses in. UIDs increase strictly with
			// arrival within a UIDVALIDITY (RFC 3501), so this is arrival order
			// by definition rather than by convention — and the order is part of
			// the contract, not a nicety. See IImapClient::fetchRecent.
			usort($messages, static fn (ImapMessage $a, ImapMessage $b): int => $a->uid <=> $b->uid);
			return $messages;
		} catch (Horde_Imap_Client_Exception $e) {
			throw new ImapException('IMAP fetch failed: ' . $this->describe($e), 0, $e);
		} finally {
			$this->logout($client);
		}
	}

	/**
	 * Build a useful message from a Horde IMAP exception. Horde's own
	 * getMessage() is often a generic translated string ("IMAP error reported by
	 * server."); the actual server response text is carried on the public
	 * `$details` property (e.g. the raw tagged NO/BAD response). Surface both.
	 *
	 * `$details` is declared `public $details;` (nullable at runtime) despite
	 * Horde's docblock typing it as string, hence the null-guard + suppressions.
	 *
	 * @psalm-suppress RedundantCastGivenDocblockType
	 * @psalm-suppress RedundantConditionGivenDocblockType
	 * @psalm-suppress DocblockTypeContradiction
	 */
	private function describe(Horde_Imap_Client_Exception $e): string {
		$message = $e->getMessage();
		$details = trim((string)($e->details ?? ''));
		if ($details !== '' && stripos($message, $details) === false) {
			$message .= ' — server said: ' . $details;
		}
		$code = $e->getCode();
		if ($code !== 0) {
			$message .= ' [code ' . $code . ']';
		}
		return $message;
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

		return new ImapMessage($messageId, $uid, $uidValidity, $subject, $date, $body, $this->firstAddress($envelope->from));
	}

	/**
	 * Format the first From address for display: the personal name when the
	 * sender gave one (that is what a mail client shows and what a human
	 * recognises), otherwise the bare address.
	 *
	 * Horde hands back a Horde_Mail_Rfc822_List, which is untyped and may be
	 * empty; a group address has no `bare_address`, hence the guards.
	 */
	private function firstAddress(mixed $list): ?string {
		if ($list === null) {
			return null;
		}
		foreach ($list as $address) {
			$name = trim((string)($address->personal ?? ''));
			$mail = trim((string)($address->bare_address ?? ''));
			if ($name !== '' && $mail !== '') {
				return $name . ' <' . $mail . '>';
			}
			$single = $name !== '' ? $name : $mail;
			if ($single !== '') {
				return $single;
			}
		}
		return null;
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
}
