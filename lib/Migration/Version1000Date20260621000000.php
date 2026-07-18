<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial Travel Manager schema.
 *
 * Five tables, all partitioned by user_id:
 *  - travelmanager_messages : IMAP dedup + ingestion audit
 *  - travelmanager_trips    : user-defined grouping of bookings
 *  - travelmanager_bookings : canonical booking (one per confirmation)
 *  - travelmanager_segments : dated items belonging to a booking
 *  - travelmanager_tasks    : Task Processing task -> message correlation
 */
class Version1000Date20260621000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			$table = $schema->createTable('travelmanager_messages');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('mailbox', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('message_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('uid_validity', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('imap_uid', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'processed']);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('processed_at', Types::DATETIME, ['notnull' => false]);
			/** @psalm-suppress DeprecatedMethod Canonical Nextcloud migration API; the runtime Table is Nextcloud's own dbal, doctrine/dbal is a dev-only type stub. */
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'message_id'], 'tm_msg_user_msgid');
			$table->addIndex(['user_id', 'status'], 'tm_msg_user_status');
		}

		if (!$schema->hasTable('travelmanager_trips')) {
			$table = $schema->createTable('travelmanager_trips');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('start_date', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('end_date', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			/** @psalm-suppress DeprecatedMethod Canonical Nextcloud migration API; the runtime Table is Nextcloud's own dbal, doctrine/dbal is a dev-only type stub. */
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'tm_trip_user');
		}

		if (!$schema->hasTable('travelmanager_bookings')) {
			$table = $schema->createTable('travelmanager_bookings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('trip_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('provider', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('booking_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'draft']);
			$table->addColumn('source_message_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('confidence', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('extraction_json', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('confirmed_at', Types::DATETIME, ['notnull' => false]);
			/** @psalm-suppress DeprecatedMethod Canonical Nextcloud migration API; the runtime Table is Nextcloud's own dbal, doctrine/dbal is a dev-only type stub. */
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'status'], 'tm_book_user_status');
			$table->addIndex(['user_id', 'trip_id'], 'tm_book_user_trip');
			$table->addIndex(['user_id', 'type', 'booking_reference'], 'tm_book_user_ref');
		}

		if (!$schema->hasTable('travelmanager_segments')) {
			$table = $schema->createTable('travelmanager_segments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('booking_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('sequence', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('start_local', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('start_timezone', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('end_local', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('end_timezone', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('origin', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('destination', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('location', Types::TEXT, ['notnull' => false]);
			$table->addColumn('flight_number', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('carrier', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('seat', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('terminal', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('gate', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('extra_json', Types::TEXT, ['notnull' => false]);
			$table->addColumn('confidence', Types::FLOAT, ['notnull' => false]);
			/** @psalm-suppress DeprecatedMethod Canonical Nextcloud migration API; the runtime Table is Nextcloud's own dbal, doctrine/dbal is a dev-only type stub. */
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'booking_id'], 'tm_seg_user_booking');
		}

		if (!$schema->hasTable('travelmanager_tasks')) {
			$table = $schema->createTable('travelmanager_tasks');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('task_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('message_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'pending']);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			/** @psalm-suppress DeprecatedMethod Canonical Nextcloud migration API; the runtime Table is Nextcloud's own dbal, doctrine/dbal is a dev-only type stub. */
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['task_id'], 'tm_task_taskid');
			$table->addIndex(['user_id', 'status'], 'tm_task_user_status');
		}

		return $schema;
	}
}
