<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\Booking;
use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Db\ProcessedMessage;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\TaskMapMapper;
use OCA\TravelManager\Db\Trip;
use OCA\TravelManager\Db\TripMapper;
use OCA\TravelManager\Exception\InvalidBackupException;
use OCA\TravelManager\Service\Dto\BackupBooking;
use OCA\TravelManager\Service\Dto\BackupContents;
use OCA\TravelManager\Service\Dto\BackupMessage;
use OCA\TravelManager\Service\Dto\BackupTrip;
use OCA\TravelManager\Service\Dto\ExistingBooking;
use OCA\TravelManager\Service\Dto\ImportPlan;
use OCA\TravelManager\Service\Dto\ImportSkip;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Backs up one user's data to a file and restores it, here or on another server.
 *
 * The file holds what cannot be recovered from elsewhere: bookings, trips and the
 * message ledger, whose RFC Message-IDs are what make the same mailbox read as
 * already processed afterwards. It leaves out settings (the IMAP password above
 * all), the debug log, and the task correlation rows, which point at tasks on the
 * exporting server. It names no user, so a backup can move between accounts.
 *
 * @psalm-import-type TravelManagerBackupCounts from \OCA\TravelManager\ResponseDefinitions
 * @psalm-import-type TravelManagerBackupReport from \OCA\TravelManager\ResponseDefinitions
 * @psalm-import-type TravelManagerBackupSkip from \OCA\TravelManager\ResponseDefinitions
 */
class BackupService {
	public function __construct(
		private BookingMapper $bookingMapper,
		private TripMapper $tripMapper,
		private ProcessedMessageMapper $messageMapper,
		private TaskMapMapper $taskMapMapper,
		private BackupFormat $format,
		private BackupImportPlanner $planner,
		private IngestionLogger $activityLog,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	/**
	 * @param bool $includeEmailText false leaves out the email bodies and raw model
	 *                               responses, for a smaller and less sensitive file
	 *                               whose messages can then never be retried
	 * @return string the backup file's contents
	 */
	public function export(string $userId, bool $includeEmailText): string {
		$trips = $this->tripMapper->findAllOldestFirst($userId);
		$bookings = $this->bookingMapper->findAllOldestFirst($userId);
		$messages = $this->messageMapper->findAllOldestFirst($userId);

		$json = json_encode([
			'format' => BackupFormat::FORMAT,
			'formatVersion' => BackupFormat::VERSION,
			'appVersion' => $this->appManager->getAppVersion('travelmanager'),
			'exportedAt' => $this->timeFactory->getDateTime()->format(BackupFormat::DATE_FORMAT),
			'includesEmailText' => $includeEmailText,
			'trips' => array_map($this->tripRow(...), $trips),
			'bookings' => array_map($this->bookingRow(...), $bookings),
			'messages' => array_map(fn (ProcessedMessage $m): array => $this->messageRow($m, $includeEmailText), $messages),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

		$this->activityLog->info(
			$userId,
			IngestionLog::STEP_BACKUP,
			'Exported a backup: ' . count($trips) . ' trip(s), ' . count($bookings) . ' booking(s), ' . count($messages) . ' message(s)'
				. ($includeEmailText ? '' : ', without email text'),
		);
		return $json;
	}

	/**
	 * What importing this file would do, with nothing written.
	 *
	 * @return TravelManagerBackupReport
	 * @throws InvalidBackupException
	 */
	public function preview(string $userId, string $json, bool $replace): array {
		$backup = $this->format->parse($json);
		$existing = $this->existingCounts($userId);
		$plan = $replace
			? $this->planner->plan($backup, [], [], [])
			: $this->planner->plan($backup, $this->messageMapper->findIdsByMessageId($userId), $this->existingBookings($userId), $this->existingTrips($userId));
		return $this->report($backup, $existing, $plan);
	}

	/**
	 * Restore a file. Validation comes first and everything else shares one
	 * transaction, so a bad file or a failed insert leaves the user's data as it
	 * was, however far along "replace" had got.
	 *
	 * Rows waiting for the model when the backup was taken return to the queue, and
	 * the next dispatcher tick sends them, under the usual limits.
	 *
	 * @return TravelManagerBackupReport
	 * @throws InvalidBackupException
	 */
	public function import(string $userId, string $json, bool $replace): array {
		$backup = $this->format->parse($json);

		$this->db->beginTransaction();
		try {
			$existing = $this->existingCounts($userId);
			if ($replace) {
				$this->bookingMapper->deleteAllForUser($userId);
				$this->tripMapper->deleteAllForUser($userId);
				$this->messageMapper->deleteAllForUser($userId);
				$this->taskMapMapper->deleteAllForUser($userId);
			}

			$existingBookings = $this->existingBookings($userId);
			$plan = $this->planner->plan($backup, $this->messageMapper->findIdsByMessageId($userId), $existingBookings, $this->existingTrips($userId));

			$tripIds = $plan->tripMatches;
			foreach ($plan->trips as $trip) {
				$tripIds[$trip->id] = $this->insertTrip($userId, $trip);
			}

			$bookingIds = [];
			foreach ($plan->bookings as $booking) {
				$bookingIds[$booking->id] = $this->insertBooking($userId, $booking, $tripIds);
			}
			foreach ($this->planner->duplicateGroups($backup, $plan, $bookingIds, $existingBookings) as $id => $group) {
				$this->bookingMapper->setDuplicateGroup($userId, $id, $group);
			}

			// A skipped booking still has a row to link to: the one it gave way to.
			$linkable = $bookingIds + $plan->bookingMatches;
			foreach ($plan->messages as $message) {
				$this->insertMessage($userId, $message, $linkable);
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$report = $this->report($backup, $existing, $plan);
		$imported = $report['imported'];
		$this->activityLog->warning(
			$userId,
			IngestionLog::STEP_BACKUP,
			($replace ? 'Replaced all data with a backup' : 'Imported a backup') . ' from ' . $backup->exportedAt . ': '
				. $imported['trips'] . ' trip(s), ' . $imported['bookings'] . ' booking(s), ' . $imported['messages'] . ' message(s) written, '
				. count($plan->skipped) . ' skipped, ' . $plan->requeued() . ' queued for the model',
			implode("\n", array_map(
				static fn (ImportSkip $skip): string => '[' . $skip->reason . '] ' . $skip->kind . ': ' . $skip->label,
				$plan->skipped,
			)) ?: null,
		);
		return $report;
	}

	/** @return TravelManagerBackupCounts */
	private function existingCounts(string $userId): array {
		return [
			'trips' => count($this->existingTrips($userId)),
			'bookings' => count($this->existingBookings($userId)),
			'messages' => count($this->messageMapper->findIdsByMessageId($userId)),
		];
	}

	/** @return list<ExistingBooking> */
	private function existingBookings(string $userId): array {
		return array_values(array_map(
			static fn (Booking $b): ExistingBooking => new ExistingBooking($b->getId(), $b->getType(), $b->getSourceMessageId(), $b->getDuplicateGroupId()),
			$this->bookingMapper->findAllOldestFirst($userId),
		));
	}

	/** @return array<int, string> */
	private function existingTrips(string $userId): array {
		$names = [];
		foreach ($this->tripMapper->findAllOldestFirst($userId) as $trip) {
			$names[$trip->getId()] = $trip->getName();
		}
		return $names;
	}

	/**
	 * @param TravelManagerBackupCounts $existing
	 * @return TravelManagerBackupReport
	 */
	private function report(BackupContents $backup, array $existing, ImportPlan $plan): array {
		return [
			'appVersion' => $backup->appVersion,
			'exportedAt' => $backup->exportedAt,
			'includesEmailText' => $backup->includesEmailText,
			'inFile' => ['trips' => count($backup->trips), 'bookings' => count($backup->bookings), 'messages' => count($backup->messages)],
			'existing' => $existing,
			'imported' => ['trips' => count($plan->trips), 'bookings' => count($plan->bookings), 'messages' => count($plan->messages)],
			'requeued' => $plan->requeued(),
			'skipped' => array_map(
				static fn (ImportSkip $skip): array => ['kind' => $skip->kind, 'reason' => $skip->reason, 'label' => $skip->label, 'existingId' => $skip->existingId],
				$plan->skipped,
			),
		];
	}

	/** @return array<string, mixed> */
	private function tripRow(Trip $trip): array {
		return [
			'id' => $trip->getId(),
			'name' => $trip->getName(),
			'type' => $trip->getType(),
			'color' => $trip->getColor(),
			'startDate' => $this->dateString($trip->getStartDate()),
			'endDate' => $this->dateString($trip->getEndDate()),
			'notes' => $trip->getNotes(),
			'createdAt' => $this->dateString($trip->getCreatedAt()),
			'updatedAt' => $this->dateString($trip->getUpdatedAt()),
		];
	}

	/** @return array<string, mixed> */
	private function bookingRow(Booking $booking): array {
		return [
			'id' => $booking->getId(),
			'tripId' => $booking->getTripId(),
			'type' => $booking->getType(),
			'provider' => $booking->getProvider(),
			'bookingReference' => $booking->getBookingReference(),
			'confirmationNumber' => $booking->getConfirmationNumber(),
			'title' => $booking->getTitle(),
			'status' => $booking->getStatus(),
			'reviewState' => $booking->getReviewState(),
			'sourceMessageId' => $booking->getSourceMessageId(),
			'duplicateGroupId' => $booking->getDuplicateGroupId(),
			'confidence' => $booking->getConfidence(),
			'details' => $booking->getDetails() === null ? null : $booking->decodedDetails(),
			'startDate' => $this->dateString($booking->getStartDate()),
			'endDate' => $this->dateString($booking->getEndDate()),
			'createdAt' => $this->dateString($booking->getCreatedAt()),
			'updatedAt' => $this->dateString($booking->getUpdatedAt()),
			'confirmedAt' => $this->dateString($booking->getConfirmedAt()),
		];
	}

	/** @return array<string, mixed> */
	private function messageRow(ProcessedMessage $message, bool $includeEmailText): array {
		$issueReasons = $message->getIssueReasons();
		$relatedBookingIds = $message->getRelatedBookingIds();
		return [
			'mailbox' => $message->getMailbox(),
			'messageId' => $message->getMessageId(),
			'uidValidity' => $message->getUidValidity(),
			'imapUid' => $message->getImapUid(),
			'status' => $message->getStatus(),
			'subject' => $message->getSubject(),
			'sender' => $message->getSender(),
			'sentAt' => $this->dateString($message->getSentAt()),
			'bodyText' => $includeEmailText ? $message->getBodyText() : null,
			'attempts' => $message->getAttempts(),
			'failureKind' => $message->getFailureKind(),
			'issueReasons' => $issueReasons === null || $issueReasons === '' ? [] : array_values(array_filter(explode(',', $issueReasons))),
			'relatedBookingIds' => $relatedBookingIds === null || $relatedBookingIds === '' ? [] : array_values(array_map('intval', array_filter(explode(',', $relatedBookingIds)))),
			'error' => $message->getError(),
			'lastResponse' => $includeEmailText ? $message->getLastResponse() : null,
			'processedAt' => $this->dateString($message->getProcessedAt()),
			'discarded' => $message->getDiscarded(),
		];
	}

	private function insertTrip(string $userId, BackupTrip $row): int {
		$trip = new Trip();
		$trip->setUserId($userId);
		$trip->setName($row->name);
		$trip->setType($row->type);
		$trip->setColor($row->color === null ? null : strtolower($row->color));
		$trip->setStartDate($this->toDateTime($row->startDate));
		$trip->setEndDate($this->toDateTime($row->endDate));
		$trip->setNotes($row->notes);
		$trip->setCreatedAt($this->toDateTime($row->createdAt));
		$trip->setUpdatedAt($this->toDateTime($row->updatedAt));
		return $this->tripMapper->insert($trip)->getId();
	}

	/** @param array<int, int> $tripIds backup trip id => id on this server */
	private function insertBooking(string $userId, BackupBooking $row, array $tripIds): int {
		$booking = new Booking();
		$booking->setUserId($userId);
		$booking->setTripId($row->tripId === null ? null : $tripIds[$row->tripId] ?? null);
		$booking->setType($row->type);
		$booking->setProvider($row->provider);
		$booking->setBookingReference($row->bookingReference);
		$booking->setConfirmationNumber($row->confirmationNumber);
		$booking->setTitle($row->title);
		$booking->setStatus($row->status);
		$booking->setReviewState($row->reviewState);
		$booking->setSourceMessageId($row->sourceMessageId);
		$booking->setConfidence($row->confidence);
		$booking->setDetails($row->details === null ? null : json_encode($row->details, JSON_THROW_ON_ERROR));
		$booking->setStartDate($this->toDateTime($row->startDate));
		$booking->setEndDate($this->toDateTime($row->endDate));
		$booking->setCreatedAt($this->toDateTime($row->createdAt));
		$booking->setUpdatedAt($this->toDateTime($row->updatedAt));
		$booking->setConfirmedAt($this->toDateTime($row->confirmedAt));
		return $this->bookingMapper->insert($booking)->getId();
	}

	/** @param array<int, int> $bookingIds backup booking id => id on this server */
	private function insertMessage(string $userId, BackupMessage $row, array $bookingIds): void {
		$related = [];
		foreach ($row->relatedBookingIds as $id) {
			if (isset($bookingIds[$id])) {
				$related[] = $bookingIds[$id];
			}
		}

		$message = new ProcessedMessage();
		$message->setUserId($userId);
		$message->setMailbox($row->mailbox);
		$message->setMessageId($row->messageId);
		$message->setUidValidity($row->uidValidity);
		$message->setImapUid($row->imapUid);
		$message->setStatus($row->isUnfinished() ? ProcessedMessage::STATUS_QUEUED : $row->status);
		$message->setSubject($row->subject);
		$message->setSender($row->sender);
		$message->setSentAt($this->toDateTime($row->sentAt));
		$message->setBodyText($row->bodyText);
		$message->setAttempts($row->attempts);
		$message->setFailureKind($row->failureKind);
		$message->setIssueReasons($row->issueReasons === [] ? null : implode(',', $row->issueReasons));
		$message->setRelatedBookingIds($related === [] ? null : implode(',', $related));
		$message->setError($row->error);
		$message->setLastResponse($row->lastResponse);
		$message->setProcessedAt($this->toDateTime($row->processedAt));
		$message->setDiscarded($row->discarded);
		$this->messageMapper->insert($message);
	}

	private function dateString(?\DateTime $date): ?string {
		return $date?->format(BackupFormat::DATE_FORMAT);
	}

	/** Already validated by BackupFormat, so this only ever sees the one form. */
	private function toDateTime(?string $value): ?\DateTime {
		return $value === null ? null : (\DateTime::createFromFormat('!' . BackupFormat::DATE_FORMAT, $value) ?: null);
	}
}
