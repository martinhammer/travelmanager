<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Service\IngestionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The ingestion ledger as a first-class view: what was read from the mailbox,
 * what became of it, and a way to re-run the ones that went wrong.
 *
 * @psalm-import-type TravelManagerMessage from \OCA\TravelManager\ResponseDefinitions
 * @psalm-import-type TravelManagerMessageBody from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class MessageController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ProcessedMessageMapper $mapper,
		private IngestionService $ingestionService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the messages ingested for the current user
	 *
	 * @param string|null $status Only return messages with this status (processing, processed, failed, no_booking, dropped)
	 * @param string|null $messageId Return only the message with this RFC Message-ID, if the user has it
	 * @return DataResponse<Http::STATUS_OK, list<TravelManagerMessage>, array{}>
	 *
	 * 200: Messages returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/messages')]
	public function index(?string $status = null, ?string $messageId = null): DataResponse {
		// Looking one up by Message-ID is how a booking finds the email that
		// created it when that email is older than the list's page. A query
		// parameter rather than a path segment: an RFC Message-ID contains '@'
		// and angle brackets, and our OCS URL helper does not escape path
		// segments (see base() in src/api.ts).
		if ($messageId !== null) {
			$found = $this->mapper->findByMessageId($this->uid(), $messageId);
			return new DataResponse($found === null ? [] : [$found->jsonSerialize()]);
		}
		$messages = $this->mapper->findAllForUser($this->uid(), $status);
		$out = array_values(array_map(static fn ($m): array => $m->jsonSerialize(), $messages));
		return new DataResponse($out);
	}

	/**
	 * Get the retained email body for a message
	 *
	 * Deliberately not part of the list response: this is the bulky column, and
	 * the Messages view only needs it for the one row the user opened.
	 *
	 * @param int $id Id of the message
	 * @return DataResponse<Http::STATUS_OK, TravelManagerMessageBody, array{}>
	 * @throws OCSNotFoundException Message not found
	 *
	 * 200: Body returned
	 * 404: Message not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/messages/{id}/body')]
	public function body(int $id): DataResponse {
		try {
			$message = $this->mapper->find($id, $this->uid());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		return new DataResponse(['id' => $id, 'bodyText' => $message->getBodyText()]);
	}

	/**
	 * Re-run the extraction for a message
	 *
	 * Uses the retained email body, so it neither re-reads the mailbox nor is
	 * blocked by the dedup ledger. The model answers asynchronously, so the
	 * result appears once the task completes.
	 *
	 * @param int $id Id of the message
	 * @return DataResponse<Http::STATUS_OK, TravelManagerMessage, array{}>
	 * @throws OCSNotFoundException Message not found
	 * @throws OCSBadRequestException The email body was not retained, so it cannot be re-extracted
	 *
	 * 200: Extraction re-scheduled
	 * 400: Message cannot be retried
	 * 404: Message not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/messages/{id}/retry')]
	public function retry(int $id): DataResponse {
		try {
			$this->ingestionService->retryMessage($this->uid(), $id);
			return new DataResponse($this->mapper->find($id, $this->uid())->jsonSerialize());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		} catch (\RuntimeException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	/**
	 * Discard a message, so it stops counting as needing attention
	 *
	 * For the rows a retry cannot help: an email that is genuinely about travel
	 * but carries nothing a booking needs will be refused again however often it
	 * is re-run. The row itself is kept — it is the dedup key, and deleting it
	 * would only have the next mailbox read ingest the same email again.
	 *
	 * @param int $id Id of the message
	 * @return DataResponse<Http::STATUS_OK, TravelManagerMessage, array{}>
	 * @throws OCSNotFoundException Message not found
	 *
	 * 200: Message discarded
	 * 404: Message not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/messages/{id}/discard')]
	public function discard(int $id): DataResponse {
		try {
			return new DataResponse($this->ingestionService->discardMessage($this->uid(), $id, true)->jsonSerialize());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	/**
	 * Restore a discarded message, so it counts as needing attention again
	 *
	 * @param int $id Id of the message
	 * @return DataResponse<Http::STATUS_OK, TravelManagerMessage, array{}>
	 * @throws OCSNotFoundException Message not found
	 *
	 * 200: Message restored
	 * 404: Message not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/messages/{id}/discard')]
	public function restore(int $id): DataResponse {
		try {
			return new DataResponse($this->ingestionService->discardMessage($this->uid(), $id, false)->jsonSerialize());
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
