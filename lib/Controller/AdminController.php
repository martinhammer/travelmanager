<?php

declare(strict_types=1);

namespace OCA\TravelManager\Controller;

use OCA\TravelManager\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Admin-only settings: the global feature flag, how much of each mailbox a run
 * reads, the two limits on how hard extraction leans on the model, and whether
 * IMAP server certificates are verified.
 *
 * @psalm-import-type TravelManagerAdminSettings from \OCA\TravelManager\ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class AdminController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the global Travel Manager admin settings
	 *
	 * @return DataResponse<Http::STATUS_OK, TravelManagerAdminSettings, array{}>
	 *
	 * 200: Admin settings returned
	 */
	#[ApiRoute(verb: 'GET', url: '/api/admin/settings')]
	public function show(): DataResponse {
		return new DataResponse([
			'enabled' => $this->configService->isFeatureEnabled(),
			'fetchPerRun' => $this->configService->getFetchPerRun(),
			'maxInFlight' => $this->configService->getMaxInFlight(),
			'maxPerHour' => $this->configService->getMaxPerHour(),
			'pdfAttachments' => $this->configService->isPdfAttachmentsEnabled(),
			'verifyImapCertificates' => $this->configService->isImapTlsVerified(),
		]);
	}

	/**
	 * Update the global Travel Manager admin settings
	 *
	 * @param bool|null $enabled Whether the extraction pipeline is enabled instance-wide
	 * @param int|null $fetchPerRun How many of the newest messages one mailbox read looks at, per user
	 * @param int|null $maxInFlight Most extractions waiting on the model at once, instance-wide (at least 1)
	 * @param int|null $maxPerHour Most extractions started per rolling hour, instance-wide (0 for no limit)
	 * @param bool|null $pdfAttachments Whether the text of PDF attachments is sent to the model with the email
	 * @param bool|null $verifyImapCertificates Whether IMAP server certificates are verified
	 * @return DataResponse<Http::STATUS_OK, TravelManagerAdminSettings, array{}>
	 *
	 * 200: Updated admin settings returned
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/admin/settings')]
	public function update(?bool $enabled = null, ?int $fetchPerRun = null, ?int $maxInFlight = null, ?int $maxPerHour = null, ?bool $pdfAttachments = null, ?bool $verifyImapCertificates = null): DataResponse {
		if ($enabled !== null) {
			$this->configService->setFeatureEnabled($enabled);
		}
		if ($fetchPerRun !== null) {
			$this->configService->setFetchPerRun($fetchPerRun);
		}
		if ($maxInFlight !== null) {
			$this->configService->setMaxInFlight($maxInFlight);
		}
		if ($maxPerHour !== null) {
			$this->configService->setMaxPerHour($maxPerHour);
		}
		if ($pdfAttachments !== null) {
			$this->configService->setPdfAttachmentsEnabled($pdfAttachments);
		}
		if ($verifyImapCertificates !== null) {
			$this->configService->setImapTlsVerified($verifyImapCertificates);
		}
		return $this->show();
	}
}
