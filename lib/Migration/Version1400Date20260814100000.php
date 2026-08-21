<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Retain what is needed to re-run an extraction, and to show the user what was
 * ingested at all.
 *
 * Until now the app kept only a dedup pointer per message — no subject, no
 * body — so a failed extraction could not be retried without re-reading IMAP,
 * and there was nothing human-readable to list. `body_text` is the plain-text
 * body that was fed to the model; `attempts` and `failure_kind` make a retry
 * bounded and let a provider/transport failure be told apart from a response we
 * could not validate.
 *
 * Retention: the body is the one bulky column here. It is dropped when the
 * bookings it produced are archived (see the archival sweep) — the point at
 * which nobody needs to re-extract it.
 */
class Version1400Date20260814100000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('subject')) {
			$table->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 255]);
		}
		if (!$table->hasColumn('sent_at')) {
			$table->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$table->hasColumn('body_text')) {
			$table->addColumn('body_text', Types::TEXT, ['notnull' => false]);
		}
		if (!$table->hasColumn('attempts')) {
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('failure_kind')) {
			$table->addColumn('failure_kind', Types::STRING, ['notnull' => false, 'length' => 32]);
		}

		return $schema;
	}
}
