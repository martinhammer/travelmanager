<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Keep the sender of the ingested email.
 *
 * The Messages view is a grid you scan, and "who sent it" is how you recognise
 * a booking email at a glance — often faster than the subject, which airlines
 * and hotels word almost identically. It is display/sort metadata only: dedup
 * still keys on the RFC Message-ID and nothing downstream reads this.
 *
 * Backfill is impossible (the envelope is not retained), so rows ingested
 * before this migration keep a null sender.
 */
class Version1600Date20260827000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('sender')) {
			$table->addColumn('sender', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		return $schema;
	}
}
