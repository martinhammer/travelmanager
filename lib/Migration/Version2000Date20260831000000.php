<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Index the duplicate-detection candidate query.
 *
 * Deduplication no longer asks the database whether a booking with a given
 * (type, provider, reference) exists — that conjunction missed real duplicates
 * whenever an email swapped the reference and confirmation number, or named the
 * broker where the previous one named the operator. It now fetches *candidates*
 * (same type, near the same date, or carrying a matching identifier) and lets
 * BookingMatcher judge them.
 *
 * That makes `start_date` a search column rather than only a sort key, hence
 * this index. `confirmation_number` joins the picture too, since identifiers
 * are matched as a set: the existing `tm_book_user_ref` covers the reference
 * half of the OR, and this covers the date half. A third index for
 * `confirmation_number` is deliberately skipped — the OR is evaluated over a
 * single user's bookings, which is a small set, and every extra index is a cost
 * on every write.
 */
class Version2000Date20260831000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('travelmanager_bookings')) {
			return null;
		}
		$table = $schema->getTable('travelmanager_bookings');

		// Short name: Nextcloud enforces a ~30-char identifier limit (Oracle).
		if (!$table->hasIndex('tm_book_user_type_start')) {
			$table->addIndex(['user_id', 'type', 'start_date'], 'tm_book_user_type_start');
		}

		return $schema;
	}
}
