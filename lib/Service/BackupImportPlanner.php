<?php

declare(strict_types=1);

namespace OCA\TravelManager\Service;

use OCA\TravelManager\Service\Dto\BackupBooking;
use OCA\TravelManager\Service\Dto\BackupContents;
use OCA\TravelManager\Service\Dto\ExistingBooking;
use OCA\TravelManager\Service\Dto\ImportPlan;
use OCA\TravelManager\Service\Dto\ImportSkip;

/**
 * Decides which rows of a backup to write and which give way to what is already
 * there. An empty database, which is what "replace" presents, matches nothing and
 * so restores the file exactly. Existing rows always win: nothing here plans an
 * overwrite, and rows from the file are only ever compared with rows from before
 * the import, never with each other, so a pair the backup holds stays a pair.
 *
 * Pure and free of OCP types (CLAUDE.md §7).
 */
class BackupImportPlanner {
	/**
	 * @param array<string, int> $existingMessages RFC Message-ID => row id
	 * @param list<ExistingBooking> $existingBookings oldest first
	 * @param array<int, string> $existingTrips id => name, oldest first
	 */
	public function plan(BackupContents $backup, array $existingMessages, array $existingBookings, array $existingTrips): ImportPlan {
		$skipped = [];

		// By name, so importing one file twice stays clean even though a trip has
		// nothing else to recognise it by. The oldest of several namesakes takes it.
		$tripsByName = [];
		foreach ($existingTrips as $id => $name) {
			$tripsByName[$this->tripKey($name)] ??= $id;
		}
		$trips = [];
		$tripMatches = [];
		foreach ($backup->trips as $trip) {
			$existing = $tripsByName[$this->tripKey($trip->name)] ?? null;
			if ($existing === null) {
				$trips[] = $trip;
				continue;
			}
			$tripMatches[$trip->id] = $existing;
			$skipped[] = new ImportSkip(ImportSkip::KIND_TRIP, ImportSkip::REASON_TRIP_EXISTS, $trip->name, $existing);
		}

		// One email can hold several bookings, so "same email" alone would let a
		// flight stand in for the hotel booked with it, and each existing booking
		// answers for one row of the file: same email, same type, oldest first.
		$available = [];
		foreach ($existingBookings as $existing) {
			if ($existing->sourceMessageId !== null) {
				$available[$this->bookingKey($existing->sourceMessageId, $existing->type)][] = $existing->id;
			}
		}
		$bookings = [];
		$bookingMatches = [];
		foreach ($backup->bookings as $booking) {
			$key = $booking->sourceMessageId === null ? null : $this->bookingKey($booking->sourceMessageId, $booking->type);
			$existing = $key === null || !isset($available[$key]) ? null : array_shift($available[$key]);
			if ($existing === null) {
				$bookings[] = $booking;
				continue;
			}
			$bookingMatches[$booking->id] = $existing;
			$skipped[] = new ImportSkip(ImportSkip::KIND_BOOKING, ImportSkip::REASON_BOOKING_EXISTS, $booking->label(), $existing);
		}

		$messages = [];
		foreach ($backup->messages as $message) {
			if (isset($existingMessages[$message->messageId])) {
				$skipped[] = new ImportSkip(ImportSkip::KIND_MESSAGE, ImportSkip::REASON_MESSAGE_EXISTS, $message->label(), $existingMessages[$message->messageId]);
			} elseif ($message->isUnfinished() && !$message->hasText()) {
				$skipped[] = new ImportSkip(ImportSkip::KIND_MESSAGE, ImportSkip::REASON_UNFINISHED_NO_TEXT, $message->label(), null);
			} else {
				$messages[] = $message;
			}
		}

		return new ImportPlan($trips, $tripMatches, $bookings, $bookingMatches, $messages, $skipped);
	}

	/**
	 * Ids to file imported bookings under, once they have ids of their own.
	 *
	 * A group spans whatever survives of it: the rows just written, plus the
	 * existing bookings that stood in for the rest. It takes the name of a group
	 * one of those existing bookings already belongs to, or else its oldest
	 * member's id, as BookingService does. A group left with one member claims
	 * nothing and is dropped.
	 *
	 * @param array<int, int> $newIds backup booking id => id it was inserted under
	 * @param list<ExistingBooking> $existingBookings
	 * @return array<int, int> booking id => group id, for every booking to stamp
	 */
	public function duplicateGroups(BackupContents $backup, ImportPlan $plan, array $newIds, array $existingBookings): array {
		$groupOf = [];
		foreach ($existingBookings as $existing) {
			$groupOf[$existing->id] = $existing->duplicateGroupId;
		}

		/** @var array<int, list<BackupBooking>> $groups */
		$groups = [];
		foreach ($backup->bookings as $booking) {
			if ($booking->duplicateGroupId !== null) {
				$groups[$booking->duplicateGroupId][] = $booking;
			}
		}

		$stamps = [];
		foreach ($groups as $members) {
			$written = [];
			$standIns = [];
			foreach ($members as $member) {
				if (isset($newIds[$member->id])) {
					$written[] = $newIds[$member->id];
				} elseif (isset($plan->bookingMatches[$member->id])) {
					$standIns[] = $plan->bookingMatches[$member->id];
				}
			}
			// Nothing new means nothing to file: the rows already here answered this.
			if ($written === [] || count($written) + count(array_unique($standIns)) < 2) {
				continue;
			}

			$group = null;
			foreach ($standIns as $id) {
				$group ??= $groupOf[$id] ?? null;
			}
			$group ??= min([...$written, ...$standIns]);

			foreach ($written as $id) {
				$stamps[$id] = $group;
			}
			foreach ($standIns as $id) {
				if (($groupOf[$id] ?? null) === null) {
					$stamps[$id] = $group;
				}
			}
		}
		return $stamps;
	}

	private function tripKey(string $name): string {
		return mb_strtolower(trim($name));
	}

	private function bookingKey(string $sourceMessageId, string $type): string {
		return $type . "\0" . $sourceMessageId;
	}
}
