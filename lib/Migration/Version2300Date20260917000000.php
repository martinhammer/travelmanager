<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * "I have seen this one and nothing can be done about it."
 *
 * The Messages view counts `failed` and `dropped` rows as needing attention, and
 * for most of them that is right — a provider timeout retries cleanly. But a
 * `dropped` row is often a correct refusal: an email that really is about travel
 * and really does not carry a departure time cannot become a booking however many
 * times it is re-run. Such a row sat in the counter permanently, which teaches
 * people to ignore the counter.
 *
 * So the ledger gets the same two-axis treatment `bookings` has: `status` stays
 * what the *pipeline* observed and is written only by the pipeline, while
 * `discarded` is the *user's* decision and is written only by an explicit user
 * action — the same word a booking uses, and for the same reason: discarding
 * says *this is not worth keeping*, which is what a dead-end extraction is.
 * (Archive was the alternative and was rejected: it means completed work, and
 * this row never was that.) Flattened into `status` it would have destroyed the
 * diagnosis — the row would no longer say *why* nothing was extracted, which is
 * exactly what the prompt-tuning workflow reads.
 *
 * Deliberately not a delete: the row is the dedup key, so removing it would have
 * the mailbox re-ingest the email on the next run and produce the same dead end.
 * It is also what a retry needs if the prompt improves later.
 *
 * A boolean, not a timestamp: nothing displays or orders by *when* it was
 * discarded, and this app already carries one write-only timestamp
 * (`bookings.confirmed_at`) as a lesson in not inventing a second.
 */
class Version2300Date20260917000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_messages')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_messages');

		// Default false, so every existing row keeps counting until the user says
		// otherwise — silently discarding the lot on upgrade would hide real
		// failures.
		if (!$table->hasColumn('discarded')) {
			$table->addColumn('discarded', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		}

		return $schema;
	}
}
