<?php
/**
 * Atomic caps, sliding windows and the replay ledger.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Every operation is one SQL statement, so concurrent requests cannot overshoot a limit.
 */
final class Counters {

	/**
	 * Spends one unit of a daily global cap.
	 *
	 * @param string $metric Metric name.
	 * @param int    $cap    Daily cap.
	 * @return bool False when the cap is reached.
	 */
	public static function spend_daily( string $metric, int $cap ): bool {
		global $wpdb;
		if ( $cap <= 0 ) {
			return false;
		}
		// Unchanged rows report zero affected rows, which is how a reached cap shows up.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (day, metric, used) VALUES (%s, %s, 1)
				ON DUPLICATE KEY UPDATE used = IF(used < %d, used + 1, used)',
				Schema::table( 'usage' ),
				gmdate( 'Y-m-d' ),
				$metric,
				$cap
			)
		);
		return (int) $affected > 0;
	}

	/**
	 * Reads today's use of a metric.
	 *
	 * @param string $metric Metric name.
	 * @return int
	 */
	public static function used_today( string $metric ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT used FROM %i WHERE day = %s AND metric = %s', Schema::table( 'usage' ), gmdate( 'Y-m-d' ), $metric )
		);
	}

	/**
	 * Spends one hit in a fixed window keyed by bucket.
	 *
	 * @param string $key     Raw key, hashed before storage.
	 * @param int    $seconds Window length.
	 * @param int    $max     Hits allowed in the window.
	 * @return bool False when the limit is reached.
	 */
	public static function hit( string $key, int $seconds, int $max ): bool {
		global $wpdb;
		if ( $max <= 0 ) {
			return false;
		}
		$now      = time();
		$affected = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, hits, window_end) VALUES (%s, 1, %d)
				ON DUPLICATE KEY UPDATE
					hits = IF(window_end <= %d, 1, IF(hits < %d, hits + 1, hits)),
					window_end = IF(window_end <= %d, %d, window_end)',
				Schema::table( 'limits' ),
				sha1( $key ),
				$now + $seconds,
				$now,
				$max,
				$now,
				$now + $seconds
			)
		);
		return (int) $affected > 0;
	}

	/**
	 * Records a nonce. Returns false when it was already used.
	 *
	 * @param string $nonce Message nonce.
	 * @param int    $ttl   Seconds to remember it.
	 * @return bool
	 */
	public static function claim_nonce( string $nonce, int $ttl ): bool {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (nonce, expires_at) VALUES (%s, %d)',
				Schema::table( 'nonces' ),
				$nonce,
				time() + $ttl
			)
		);
		return 1 === (int) $affected;
	}

	/**
	 * Deletes expired windows, nonces and old usage rows.
	 *
	 * @return void
	 */
	public static function purge(): void {
		global $wpdb;
		$now = time();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_end <= %d', Schema::table( 'limits' ), $now ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %d', Schema::table( 'nonces' ), $now ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', Schema::table( 'usage' ), gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS ) ) );
	}
}
