<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Exception\PdfException;
use OCA\TravelManager\Imap\AttachmentRef;
use OCA\TravelManager\Imap\IImapClient;
use OCA\TravelManager\Imap\ImapConnection;
use OCA\TravelManager\Imap\ImapMessage;
use OCA\TravelManager\Pdf\IPdfReader;
use OCA\TravelManager\Service\Dto\AttachmentRecord;
use OCA\TravelManager\Service\Dto\AttachmentText;

/**
 * Reads the PDF attachments of one new email, for the text the model needs when
 * the body says no more than "your ticket is attached".
 *
 * Every PDF found is accounted for in the result, read or not: a skipped file
 * that leaves no trace is indistinguishable from an email that had none.
 *
 * Called only after dedup, so nothing is downloaded for mail already processed.
 *
 * The crash breadcrumb outlives read(): it stays set until the caller has
 * stored the message and calls finish(). A fatal error between reading the
 * PDFs and storing the row — assembling the text, encoding the records, the
 * insert itself — otherwise left no trace, and the next run fetched the same
 * message and read the same PDFs again.
 */
class AttachmentService {
	/** Invoices and terms-and-conditions files ride along with e-tickets. */
	public const MAX_PDFS = 3;
	/** Decoded size; the library holds the whole file in memory. */
	public const MAX_BYTES = 5 * 1024 * 1024;
	/** The booking is on page 1 in every real ticket seen; the terms run on. */
	public const MAX_PAGES = 10;

	/** The user whose breadcrumb this request set and has not cleared yet. */
	private ?string $breadcrumbFor = null;

	public function __construct(
		private IImapClient $imapClient,
		private IPdfReader $pdfReader,
		private ConfigService $configService,
		private IngestionLogger $activityLog,
	) {
	}

	/**
	 * Clear the breadcrumb read() left, once the message is stored or has
	 * failed in a way PHP survived. A no-op when read() set none, so callers
	 * need not know whether there were PDFs.
	 */
	public function finish(string $userId): void {
		if ($this->breadcrumbFor !== $userId) {
			return;
		}
		$this->breadcrumbFor = null;
		$this->configService->setPdfReading($userId, null);
	}

	public function read(string $userId, ImapConnection $connection, ImapMessage $message): AttachmentText {
		$refs = $message->attachments;
		if ($refs === []) {
			return new AttachmentText();
		}

		if (!$this->configService->isPdfAttachmentsEnabled()) {
			return new AttachmentText([], array_map(
				static fn (AttachmentRef $ref): AttachmentRecord => new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_DISABLED),
				$refs,
			));
		}

		// The last run died inside one of these files. Reading them again would
		// only die again, every run, with this user's mail stuck behind it.
		if ($this->configService->getPdfReading($userId) === $message->messageId) {
			// Left set until finish(): if storing the message dies too, the next
			// run must still skip these files rather than read them again.
			$this->breadcrumbFor = $userId;
			$this->activityLog->error(
				$userId,
				IngestionLog::STEP_FETCH,
				'The last mailbox read stopped while reading a PDF attachment of ' . $message->messageId
					. '; its attachments are skipped so the rest of the mail can go on',
			);
			return new AttachmentText([], array_map(
				static fn (AttachmentRef $ref): AttachmentRecord => new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_CRASHED),
				$refs,
			));
		}

		/** @var array<int, AttachmentRecord> $records by position in $refs */
		$records = [];
		/** @var array<int, AttachmentRef> $wanted */
		$wanted = [];
		foreach ($refs as $i => $ref) {
			if (count($wanted) >= self::MAX_PDFS) {
				$records[$i] = new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_TOO_MANY);
			} elseif ($ref->bytes > self::MAX_BYTES) {
				// Already the decoded estimate: Horde scales a base64 part's
				// BODYSTRUCTURE size by 3/4 before setBytes(), so scaling it here
				// again let files of up to ~6.7 MB be downloaded only to be
				// refused by the real size below.
				$records[$i] = new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_TOO_LARGE);
			} else {
				$wanted[$i] = $ref;
			}
		}

		$bytes = $this->download($userId, $connection, $message, $wanted);
		if ($bytes === []) {
			foreach ($wanted as $i => $ref) {
				$records[$i] = new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_FAILED);
			}
			ksort($records);
			return new AttachmentText([], array_values($records));
		}

		$sections = [];
		// Cleared by finish(), after the message is stored — not here.
		$this->configService->setPdfReading($userId, $message->messageId);
		$this->breadcrumbFor = $userId;
		foreach ($wanted as $i => $ref) {
			$file = $bytes[$ref->mimeId] ?? null;
			[$records[$i], $section] = $file === null
				? [new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_FAILED), null]
				: $this->readOne($userId, $ref, $file, $i + 1);
			if ($section !== null) {
				$sections[] = $section;
			}
		}

		ksort($records);
		return new AttachmentText($sections, array_values($records));
	}

	/**
	 * @param array<int, AttachmentRef> $wanted
	 * @return array<string, string> decoded bytes by MIME id; a failed download is simply absent
	 */
	private function download(string $userId, ImapConnection $connection, ImapMessage $message, array $wanted): array {
		if ($wanted === []) {
			return [];
		}
		try {
			return $this->imapClient->fetchParts(
				$connection,
				$message->uidValidity,
				$message->uid,
				array_values(array_map(static fn (AttachmentRef $ref): string => $ref->mimeId, $wanted)),
			);
		} catch (\Throwable $e) {
			$this->activityLog->warning(
				$userId,
				IngestionLog::STEP_FETCH,
				'Could not download the PDF attachments of ' . $message->messageId . ': ' . $e->getMessage(),
			);
			return [];
		}
	}

	/**
	 * @return array{0: AttachmentRecord, 1: ?string} the record, and the prompt section when there is text
	 */
	private function readOne(string $userId, AttachmentRef $ref, string $file, int $index): array {
		$name = $ref->filename ?? 'attachment ' . $index;
		if (strlen($file) > self::MAX_BYTES) {
			// The estimate from the encoded size let it through; the real size did not.
			return [new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_TOO_LARGE), null];
		}

		try {
			$pdf = $this->pdfReader->read($file, self::MAX_PAGES);
		} catch (PdfException $e) {
			$this->activityLog->warning($userId, IngestionLog::STEP_FETCH, 'Could not read PDF attachment "' . $name . '": ' . $e->getMessage());
			return [new AttachmentRecord($ref->filename, $e->encrypted ? AttachmentRecord::OUTCOME_ENCRYPTED : AttachmentRecord::OUTCOME_FAILED), null];
		} catch (\Throwable $e) {
			// Anything else costs this file, never the message or the run.
			$this->activityLog->warning($userId, IngestionLog::STEP_FETCH, 'Could not read PDF attachment "' . $name . '": ' . $e->getMessage());
			return [new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_FAILED), null];
		}

		if ($pdf->text === '') {
			$this->activityLog->info($userId, IngestionLog::STEP_FETCH, 'PDF attachment "' . $name . '" has no text layer (probably scanned), not read');
			return [new AttachmentRecord($ref->filename, AttachmentRecord::OUTCOME_NO_TEXT, $pdf->pages, $pdf->pagesRead, 0), null];
		}

		[$text, $cut] = MessageText::truncate($pdf->text, MessageText::PER_ATTACHMENT);
		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_FETCH,
			'Read PDF attachment "' . $name . '": ' . $pdf->pagesRead . ' of ' . $pdf->pages . ' page(s), ' . strlen($text) . ' bytes of text',
		);
		return [
			new AttachmentRecord(
				$ref->filename,
				AttachmentRecord::OUTCOME_READ,
				$pdf->pages,
				$pdf->pagesRead,
				strlen($text),
				$cut || $pdf->pagesRead < $pdf->pages,
			),
			MessageText::section($ref->filename, $index, $pdf->pages, $pdf->pagesRead, $text),
		];
	}
}
