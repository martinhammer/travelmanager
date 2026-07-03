<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Db\BookingMapper;
use OCA\TravelManager\Db\IngestionLog;
use OCA\TravelManager\Db\ProcessedMessageMapper;
use OCA\TravelManager\Db\SegmentMapper;
use OCA\TravelManager\Db\TaskMapMapper;
use OCP\IDBConnection;

/**
 * Developer/debugging maintenance actions scoped to a single user.
 *
 * Wiping clears the extracted bookings/segments, the IMAP dedup ledger and the
 * task-correlation rows so the very same mailbox messages are reprocessed from
 * scratch on the next run. User-created trips are intentionally kept (they are
 * manual groupings, not derived data); the activity log is kept too so the wipe
 * itself stays auditable.
 */
class MaintenanceService {
	public function __construct(
		private BookingMapper $bookingMapper,
		private SegmentMapper $segmentMapper,
		private ProcessedMessageMapper $processedMessageMapper,
		private TaskMapMapper $taskMapMapper,
		private IngestionLogger $activityLog,
		private IDBConnection $db,
	) {
	}

	/**
	 * Delete this user's derived travel data so the mailbox can be reprocessed.
	 */
	public function wipeUserData(string $userId): void {
		$this->db->beginTransaction();
		try {
			$this->segmentMapper->deleteAllForUser($userId);
			$this->bookingMapper->deleteAllForUser($userId);
			$this->processedMessageMapper->deleteAllForUser($userId);
			$this->taskMapMapper->deleteAllForUser($userId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->activityLog->warning(
			$userId,
			IngestionLog::STEP_WIPE,
			'Wiped extracted bookings, segments, processed-message ledger and tasks — mailbox will be reprocessed from scratch',
		);
	}
}
