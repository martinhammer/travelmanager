<?php

declare(strict_types=1);

namespace OCA\TravelManager\Migration;

use Closure;
use OCA\TravelManager\AppInfo\Application;
use OCA\TravelManager\Service\ConfigService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Move the global feature flag off the reserved `enabled` app-config key, and
 * repair the row if we already wrote to it.
 *
 * `enabled` is the server's own record of whether an app is enabled. Core writes
 * it as the string 'yes' with the declared type **VALUE_MIXED**, and MIXED is
 * deliberately permissive: `OC\AppConfig::setTypedValue` skips its type-conflict
 * check entirely when the stored type is MIXED. So our `setValueBool()` was
 * allowed to overwrite the row — value '1', type VALUE_BOOL — with no error and a
 * 200 on the settings save.
 *
 * The damage lands on the next request, not that one. `getAppInstalledVersions()`
 * reads every app's `enabled` as a **string**, and against a BOOL-typed row that
 * throws AppConfigTypeConflictException. It is reached from
 * `Memcache\Factory::getGlobalPrefix()` while building `OC\User\Manager` — i.e.
 * during bootstrap — so the throw is uncaught on *every* request, web and occ
 * alike. An instance in this state cannot even answer `occ status`, which is how
 * one dev container talked its entrypoint into re-running `maintenance:install`
 * and overwriting its own config.php.
 *
 * Note the blast radius is the whole server, not this app: one wrong key name in
 * one app's settings save takes the instance down. Hence the flag is now
 * `pipeline_enabled` and `enabled` is never written again.
 *
 * Runs against `appconfig` directly rather than through IAppConfig because the
 * row's *type* has to change back, and `updateType()` is internal to
 * `OC\AppConfig` — it is deliberately not on the public IAppConfig.
 *
 * No-ops on an instance that never saved the admin panel: its `enabled` is still
 * MIXED and untouched, and there is no flag value to carry across.
 */
class Version2500Date20260918000000 extends SimpleMigrationStep {
	private const RESERVED_KEY = 'enabled';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$row = $this->readReserved();
		if ($row === null) {
			return;
		}

		[$value, $type] = $row;
		if ($type === IAppConfig::VALUE_MIXED) {
			// Core's own row, untouched — this instance never saved the panel.
			return;
		}

		// Carry the admin's actual choice across rather than defaulting to off:
		// the pipeline silently stopping is the sort of thing that gets noticed
		// days later, by which point nobody connects it to an upgrade.
		$this->writeFlag($value === '1');
		$this->restoreReserved();

		$output->info(
			'Travel Manager: moved the feature flag off the reserved "enabled" key to "'
			. ConfigService::APP_PIPELINE_ENABLED . '" and restored the app-enabled row',
		);
	}

	/**
	 * @return array{0: string, 1: int}|null the reserved row's value and type
	 */
	private function readReserved(): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue', 'type')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::RESERVED_KEY)));

		$result = $qb->executeQuery();
		/** @var array{configvalue?: scalar|null, type?: scalar|null}|false $row */
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}
		return [(string)($row['configvalue'] ?? ''), (int)($row['type'] ?? 0)];
	}

	private function writeFlag(bool $enabled): void {
		$value = $enabled ? '1' : '0';

		$qb = $this->db->getQueryBuilder();
		$qb->select('configkey')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(ConfigService::APP_PIPELINE_ENABLED)));
		$result = $qb->executeQuery();
		$exists = $result->fetch() !== false;
		$result->closeCursor();

		$write = $this->db->getQueryBuilder();
		if ($exists) {
			$write->update('appconfig')
				->set('configvalue', $write->createNamedParameter($value))
				->set('type', $write->createNamedParameter(IAppConfig::VALUE_BOOL, IQueryBuilder::PARAM_INT))
				->where($write->expr()->eq('appid', $write->createNamedParameter(Application::APP_ID)))
				->andWhere($write->expr()->eq('configkey', $write->createNamedParameter(ConfigService::APP_PIPELINE_ENABLED)));
		} else {
			$write->insert('appconfig')
				->values([
					'appid' => $write->createNamedParameter(Application::APP_ID),
					'configkey' => $write->createNamedParameter(ConfigService::APP_PIPELINE_ENABLED),
					'configvalue' => $write->createNamedParameter($value),
					'type' => $write->createNamedParameter(IAppConfig::VALUE_BOOL, IQueryBuilder::PARAM_INT),
					'lazy' => $write->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				]);
		}
		$write->executeStatement();
	}

	/**
	 * Put the server's row back exactly as core writes it. 'yes' rather than the
	 * stored flag: this migration only runs while the app is being upgraded, which
	 * only happens while it is enabled.
	 */
	private function restoreReserved(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('appconfig')
			->set('configvalue', $qb->createNamedParameter('yes'))
			->set('type', $qb->createNamedParameter(IAppConfig::VALUE_MIXED, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::RESERVED_KEY)));
		$qb->executeStatement();
	}
}
