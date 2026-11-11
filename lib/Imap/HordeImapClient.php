<?php

declare(strict_types=1);

namespace OCA\TravelManager\Imap;

use Horde_Imap_Client;
use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Ids;
use Horde_Imap_Client_Socket;
use Horde_Mime_Part;
use OCA\TravelManager\Exception\ImapException;
use OCA\TravelManager\Service\ConfigService;
use OCP\ICertificateManager;
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
	/**
	 * Memory guard on the body text, in bytes (of UTF-8). Not the budget the
	 * model sees: that depends on the attachments too, and is applied by
	 * MessageText::assemble once they are known.
	 */
	private const MAX_BODY_LENGTH = 100000;

	public function __construct(
		private LoggerInterface $logger,
		private ConfigService $configService,
		private ImapEndpointResolver $endpointResolver,
		private ICertificateManager $certificateManager,
	) {
	}

	public function verify(ImapConnection $connection): void {
		$client = $this->createSocket($connection);
		try {
			$client->login();
			// Opening read-only confirms the mailbox exists and is selectable.
			$client->openMailbox($connection->mailbox, Horde_Imap_Client::OPEN_READONLY);
		} catch (Horde_Imap_Client_Exception $e) {
			throw new ImapException('IMAP verification failed: ' . $this->describe($e, $connection), 0, $e);
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
			throw new ImapException('IMAP fetch failed: ' . $this->describe($e, $connection), 0, $e);
		} finally {
			$this->logout($client);
		}
	}

	public function fetchParts(ImapConnection $connection, int $uidValidity, int $uid, array $mimeIds): array {
		if ($mimeIds === []) {
			return [];
		}
		$client = $this->createSocket($connection);
		$mailbox = $connection->mailbox;
		try {
			$client->openMailbox($mailbox, Horde_Imap_Client::OPEN_READONLY);
			$status = $client->status($mailbox, Horde_Imap_Client::STATUS_UIDVALIDITY);
			if ((int)($status['uidvalidity'] ?? 0) !== $uidValidity) {
				throw new ImapException('The mailbox changed (UIDVALIDITY) since it was read, so the message cannot be found by its UID');
			}

			// The structure again, for each part's transfer encoding: the bytes
			// come back still encoded, and the part object is what decodes them.
			$query = new Horde_Imap_Client_Fetch_Query();
			$query->structure();
			foreach ($mimeIds as $mimeId) {
				// `peek` => do NOT set the \Seen flag (read-only, V6).
				$query->bodyPart($mimeId, ['peek' => true]);
			}
			$result = $client->fetch($mailbox, $query, ['ids' => new Horde_Imap_Client_Ids([$uid])]);

			$data = null;
			foreach ($result as $row) {
				$data = $row;
				break;
			}
			if ($data === null) {
				return [];
			}

			$structure = $data->getStructure();
			$structure->buildMimeIds();
			$parts = [];
			foreach ($mimeIds as $mimeId) {
				$part = $structure->getPart($mimeId);
				$raw = $data->getBodyPart($mimeId);
				if (!$part instanceof Horde_Mime_Part || $raw === null) {
					continue;
				}
				// One part that will not decode costs that part only.
				try {
					$part->setContents((string)$raw, ['encoding' => $data->getBodyPartDecode($mimeId)]);
					$parts[$mimeId] = (string)$part->getContents();
				} catch (\Throwable $e) {
					$this->logger->warning('Travel Manager: could not decode MIME part ' . $mimeId . ': ' . $e->getMessage());
				}
			}
			return $parts;
		} catch (Horde_Imap_Client_Exception $e) {
			throw new ImapException('IMAP fetch failed: ' . $this->describe($e, $connection), 0, $e);
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
	private function describe(Horde_Imap_Client_Exception $e, ImapConnection $connection): string {
		$message = $e->getMessage();
		$details = trim((string)($e->details ?? ''));
		if ($details !== '' && stripos($message, $details) === false) {
			$message .= ' — server said: ' . $details;
		}
		// A refused certificate surfaces as a bare connect failure: the TLS error
		// is a PHP warning Horde suppresses, leaving `[0]` as the details. Since
		// verification is new for servers that were working without it, say so.
		if ($e->getCode() === Horde_Imap_Client_Exception::SERVER_CONNECT
			&& strtolower($connection->security) !== ImapEndpoint::SECURITY_NONE
			&& $this->configService->isImapTlsVerified()) {
			$message .= ' — this can also mean the server\'s certificate could not be verified for '
				. $connection->host . '. A self-signed certificate needs its CA imported by the administrator'
				. ' (occ security:certificates:import).';
		}
		$code = $e->getCode();
		if ($code !== 0) {
			$message .= ' [code ' . $code . ']';
		}
		return $message;
	}

	/**
	 * A client for this connection, checked and pinned before anything is sent.
	 *
	 * Checked again here, not only on save: rows saved before the rules existed
	 * are still in the database, and cron dials every enrolled user.
	 *
	 * Horde's socket client turns certificate checks *off* unless told otherwise
	 * (`Horde\Socket\Client::__construct` merges `verify_peer => false` under the
	 * caller's context), so the context below is what verifies anything at all.
	 * The bundle is Nextcloud's own, the one its HTTP client uses, so a CA an
	 * admin imported with `occ security:certificates:import` is trusted here too.
	 *
	 * The host is dialled by the address the resolver checked, never by name
	 * (see ImapEndpointResolver), so the name goes in `peer_name`: that is what
	 * the certificate is verified against and what SNI sends.
	 *
	 * @throws ImapException when the endpoint is invalid or may not be reached
	 */
	private function createSocket(ImapConnection $connection): Horde_Imap_Client_Socket {
		$problem = ImapEndpoint::problem($connection->host, $connection->port, strtolower($connection->security));
		if ($problem !== null) {
			throw new ImapException($problem);
		}
		$address = $this->endpointResolver->resolve($connection->host);

		$secure = match (strtolower($connection->security)) {
			ImapEndpoint::SECURITY_SSL => 'ssl',
			ImapEndpoint::SECURITY_TLS => 'tls',
			default => false,
		};
		return new Horde_Imap_Client_Socket([
			'username' => $connection->user,
			'password' => $connection->password,
			'hostspec' => $address,
			'port' => $connection->port,
			'secure' => $secure,
			'timeout' => 20,
			'context' => ['ssl' => $this->sslContext($connection->host)],
		]);
	}

	/**
	 * @return array<string, bool|string>
	 */
	private function sslContext(string $host): array {
		$peerName = ImapEndpoint::ipLiteral($host) ?? $host;
		if (!$this->configService->isImapTlsVerified()) {
			return [
				'verify_peer' => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true,
				'peer_name' => $peerName,
			];
		}
		$context = [
			'verify_peer' => true,
			'verify_peer_name' => true,
			'allow_self_signed' => false,
			'peer_name' => $peerName,
		];
		try {
			$context['cafile'] = $this->certificateManager->getAbsoluteBundlePath();
		} catch (\Throwable $e) {
			// Still verified, against the system's CA store instead.
			$this->logger->warning('Travel Manager: could not locate the certificate bundle, using the system CA store: ' . $e->getMessage());
		}
		return $context;
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
		// Only worth carrying when there is nothing else to go on.
		$structure = $body === '' ? MimeText::describe($data->getStructure()) : null;

		return new ImapMessage(
			$messageId,
			$uid,
			$uidValidity,
			$subject,
			$date,
			$body,
			$this->firstAddress($envelope->from),
			$structure,
			MimeText::pdfAttachments($data->getStructure()),
		);
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
	 * Fetch and decode the text of every inline text part (see MimeText for
	 * which ones, and why not just the first), normalised to UTF-8 and bounded
	 * by the memory guard.
	 */
	private function extractText(Horde_Imap_Client_Socket $client, string $mailbox, mixed $data): string {
		$structure = $data->getStructure();
		$partIds = MimeText::partIds($structure);
		if ($partIds === []) {
			return '';
		}

		$query = new Horde_Imap_Client_Fetch_Query();
		foreach ($partIds as $partId) {
			// `peek` => do NOT set the \Seen flag (read-only, V6).
			$query->bodyPart($partId, ['peek' => true]);
		}
		$result = $client->fetch($mailbox, $query, ['ids' => new Horde_Imap_Client_Ids([$data->getUid()])]);

		$bodyData = null;
		foreach ($result as $row) {
			$bodyData = $row;
			break;
		}
		if ($bodyData === null) {
			return '';
		}

		$text = MimeText::text($structure, function (Horde_Mime_Part $part) use ($bodyData): string {
			$partId = $part->getMimeId();
			// One part that will not decode costs that part, never the message
			// or the rest of the mailbox read: this runs inside the fetch loop,
			// and the same email is read again on every run.
			try {
				$part->setContents(
					(string)$bodyData->getBodyPart($partId),
					['encoding' => $bodyData->getBodyPartDecode($partId)],
				);
				return MimeText::toUtf8((string)$part->getContents(), (string)$part->getCharset());
			} catch (\Throwable $e) {
				$this->logger->warning('Travel Manager: could not decode MIME part ' . $partId . ': ' . $e->getMessage());
				return '';
			}
		});
		return $this->normalise($text);
	}

	private function normalise(string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		$text = trim($text);
		if (strlen($text) > self::MAX_BODY_LENGTH) {
			// mb_strcut, not substr: a cut through a multibyte character would
			// leave invalid UTF-8 for the database and the prompt.
			$text = mb_strcut($text, 0, self::MAX_BODY_LENGTH, 'UTF-8');
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
