<?php
/**
 * Scheduled upkeep and the delete-my-data flow.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scan;

use Oldev\HealthReport\Crm\CrmGateway;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;

defined( 'ABSPATH' ) || exit;

/**
 * Watchdog, 30-day retention and erasure.
 */
final class Maintenance {

	public const WATCHDOG_ACTION  = 'shr_watchdog';
	public const RETENTION_ACTION = 'shr_retention';
	public const RETENTION_DAYS   = 30;
	public const STUCK_MINUTES    = 10;

	/**
	 * Wires collaborators.
	 *
	 * @param CrmGateway $crm CRM gateway.
	 */
	public function __construct( private CrmGateway $crm ) {}

	/**
	 * Registers recurring actions once.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! as_has_scheduled_action( self::WATCHDOG_ACTION, array(), ScanService::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, 300, self::WATCHDOG_ACTION, array(), ScanService::GROUP );
		}
		if ( ! as_has_scheduled_action( self::RETENTION_ACTION, array(), ScanService::GROUP ) ) {
			as_schedule_recurring_action( time() + 3600, DAY_IN_SECONDS, self::RETENTION_ACTION, array(), ScanService::GROUP );
		}
	}

	/**
	 * Fails scans that never produced a result.
	 *
	 * @return int Scans failed.
	 */
	public function watchdog(): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS );
		$failed = 0;
		foreach ( Scans::stuck_since( $cutoff ) as $scan ) {
			$finished = Scans::finish(
				(int) $scan['id'],
				array(
					'status'         => Scans::FAILED,
					'failure_reason' => 'timed_out',
				)
			);
			if ( $finished ) {
				AbuseLog::record( 'scan_timed_out', '', (string) $scan['domain'] );
				++$failed;
			}
		}
		return $failed;
	}

	/**
	 * Applies the 30-day retention rule.
	 *
	 * @return int Scans deleted.
	 */
	public function retention(): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$deleted = Scans::delete_older_than( $cutoff );

		foreach ( Owners::without_scans( $cutoff ) as $owner ) {
			$this->crm->erase( (string) $owner['email'] );
			Owners::delete( (int) $owner['id'] );
		}

		AbuseLog::purge( $cutoff );
		Owners::purge_links();
		Counters::purge();

		return $deleted;
	}

	/**
	 * Erases everything held about an owner.
	 *
	 * @param int $owner_id Owner id.
	 * @return void
	 */
	public function erase_owner( int $owner_id ): void {
		$owner = Owners::find( $owner_id );
		if ( null === $owner ) {
			return;
		}
		$this->crm->erase( (string) $owner['email'] );
		Scans::delete_for_owner( $owner_id );
		Owners::delete( $owner_id );
	}
}
