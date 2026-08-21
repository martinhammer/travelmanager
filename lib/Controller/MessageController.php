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
	 * @return DataResponse<Http::STATUS_OK, list<TravelManagerMessage>, array{}>
	 *
	 * 200: Messages returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/messages')]
	public function index(?string $status = null): DataResponse {
		$messages = $this->mapper->findAllForUser($this->uid(), $status);
		$out = array_values(array_map(static fn ($m): array => $m->jsonSerialize(), $messages));
		return new DataResponse($out);
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

	private function uid(): string {
		return $this->userId ?? '';
	}
}
