<?php
/**
 * Abuse and security event log.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only log shown on the admin screen.
 */
final class AbuseLog {

	/**
	 * Records an event.
	 *
	 * @param string $event   Event key.
	 * @param string $ip_hash Keyed IP hash, or empty.
	 * @param string $domain  Domain, or empty.
	 * @param string $detail  Short detail, never personal data.
	 * @return void
	 */
	public static function record( string $event, string $ip_hash = '', string $domain = '', string $detail = '' ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'abuse_log' ),
			array(
				'event'      => substr( $event, 0, 40 ),
				'ip_hash'    => $ip_hash,
				'domain'     => substr( $domain, 0, 253 ),
				'detail'     => substr( $detail, 0, 190 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Returns the newest events.
	 *
	 * @param int $limit Rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::table( 'abuse_log' ), $limit ),
			ARRAY_A
		);
	}

	/**
	 * Counts events by key for today.
	 *
	 * @return array<string,int>
	 */
	public static function counts_today(): array {
		global $wpdb;
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY event',
				Schema::table( 'abuse_log' ),
				gmdate( 'Y-m-d 00:00:00' )
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['event'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Deletes events older than a cutoff.
	 *
	 * @param string $cutoff GMT datetime.
	 * @return void
	 */
	public static function purge( string $cutoff ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Schema::table( 'abuse_log' ), $cutoff ) );
	}
}
