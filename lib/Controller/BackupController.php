<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Exception\InvalidBackupException;
use OCA\TravelManager\Service\BackupService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Back up the current user's data to a file, and restore it here or elsewhere.
 *
 * @psalm-import-type TravelManagerBackupReport from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class BackupController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private BackupService $backupService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Download a backup of the current user's bookings, trips and messages
	 *
	 * @param bool $includeEmailText Whether to include email bodies and raw model responses
	 * @return DataDownloadResponse<Http::STATUS_OK, 'application/json', array{}>
	 *
	 * 200: Backup file returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/backup/export')]
	public function export(bool $includeEmailText = true): DataDownloadResponse {
		return new DataDownloadResponse(
			$this->backupService->export($this->uid(), $includeEmailText),
			'travelmanager-backup.json',
			'application/json',
		);
	}

	/**
	 * Check an uploaded backup and report what importing it would do
	 *
	 * Writes nothing. The file is the `backup` part of a multipart request.
	 *
	 * @param bool $replace Whether the import would first delete the existing data
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBackupReport, array{}>
	 * @throws OCSBadRequestException The file is missing or is not a valid backup
	 *
	 * 200: Report returned
	 * 400: The file is missing or is not a valid backup
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/backup/preview')]
	public function preview(bool $replace = false): DataResponse {
		try {
			return new DataResponse($this->backupService->preview($this->uid(), $this->uploadedBackup(), $replace));
		} catch (InvalidBackupException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	/**
	 * Restore an uploaded backup into the current user's data
	 *
	 * The file is the `backup` part of a multipart request. It is validated in full
	 * before anything is changed, and a failure part way through changes nothing.
	 *
	 * @param bool $replace Whether to delete the existing data first
	 * @return DataResponse<Http::STATUS_OK, TravelManagerBackupReport, array{}>
	 * @throws OCSBadRequestException The file is missing or is not a valid backup
	 *
	 * 200: Backup restored
	 * 400: The file is missing or is not a valid backup
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/backup/import')]
	public function import(bool $replace = false): DataResponse {
		try {
			return new DataResponse($this->backupService->import($this->uid(), $this->uploadedBackup(), $replace));
		} catch (InvalidBackupException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	/**
	 * @throws InvalidBackupException
	 */
	private function uploadedBackup(): string {
		/** @var array<array-key, mixed>|null $file declared as array, but null when absent */
		$file = $this->request->getUploadedFile('backup');
		// PHP drops the whole request body when post_max_size is exceeded, so a file
		// that is too large can also arrive as no file at all.
		if (!is_array($file)) {
			throw new InvalidBackupException('No backup file was received. If it is large, it may exceed the upload limit of this server.');
		}
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			throw new InvalidBackupException('This backup exceeds the upload limit of this server.');
		}
		$path = $file['tmp_name'] ?? null;
		if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) {
			throw new InvalidBackupException('The backup file could not be uploaded.');
		}
		$contents = file_get_contents($path);
		if ($contents === false) {
			throw new InvalidBackupException('The backup file could not be read.');
		}
		return $contents;
	}

	private function uid(): string {
		return $this->userId ?? '';
	}
}
