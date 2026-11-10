<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What was read from an email's PDF attachments.
 *
 * Some booking emails say only "your ticket is attached", so the text of PDF
 * attachments now goes to the model with the body, inside `body_text` under a
 * heading per file. That keeps `body_text` what it always was — exactly what the
 * model was given — but it cannot say what was *not* read: the scan with no
 * text, the file over the size cap, the fourth PDF, the one that needed a
 * password. Without that a skipped file leaves no trace, and the email is
 * indistinguishable from one that had none.
 *
 * So each PDF found gets an entry here: filename, page counts, outcome. A JSON
 * list rather than comma-separated slugs as `issue_reasons` is, because the
 * Messages view names the file and its pages.
 *
 * Its own column rather than more `issue_reasons`: those describe the last
 * extraction attempt and a retry clears them, while what was read from an
 * attachment is fixed at ingestion and true for every attempt after it.
 *
 * Nullable with no backfill: the rows before this were ingested without reading
 * attachments, and their files are not retained to read now.
 */
class Version2600Date20261110000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		if (!$table->hasColumn('attachments')) {
			$table->addColumn('attachments', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
