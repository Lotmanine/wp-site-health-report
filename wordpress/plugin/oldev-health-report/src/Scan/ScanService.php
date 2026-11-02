<?php
/**
 * Scan intake.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scan;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Security\ClientIp;
use Oldev\HealthReport\Security\Signature;
use Oldev\HealthReport\Security\Turnstile;
use Oldev\HealthReport\Security\UrlGuard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates requests, applies limits and queues scans.
 */
final class ScanService {

	public const GROUP = 'shr';

	/**
	 * Handles a public scan request.
	 *
	 * @param array<string,mixed> $input Request fields.
	 * @param string              $ip    Client address.
	 * @return array<string,mixed>|WP_Error
	 */
	public function submit( array $input, string $ip ) {
		$ip_hash = ClientIp::hash( $ip );

		if ( true !== ( $input['consent'] ?? false ) ) {
			return new WP_Error( 'shr_consent_required', __( 'Confirm that you own this website or may test it.', 'oldev-health-report' ), array( 'status' => 422 ) );
		}

		$target = UrlGuard::normalize( (string) ( $input['url'] ?? '' ) );
		if ( is_wp_error( $target ) ) {
			AbuseLog::record( 'url_rejected', $ip_hash, '', $target->get_error_code() );
			$target->add_data( array( 'status' => 422 ) );
			return $target;
		}

		$email = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'shr_invalid_email', __( 'Enter a valid email address.', 'oldev-health-report' ), array( 'status' => 422 ) );
		}

		$limited = $this->check_ip_limits( $ip_hash );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( ! Turnstile::verify( (string) ( $input['turnstile_token'] ?? '' ), $ip ) ) {
			AbuseLog::record( 'turnstile_rejected', $ip_hash, $target['domain'] );
			return new WP_Error( 'shr_turnstile_failed', __( 'The security check failed. Reload the page and try again.', 'oldev-health-report' ), array( 'status' => 403 ) );
		}

		$allowed = $this->check_capacity( $target['domain'], $ip_hash );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$wants_pdf = '' !== $email && true === ( $input['email_consent'] ?? false );
		$marketing = '' !== $email && true === ( $input['marketing_consent'] ?? false );
		$owner_id  = null;
		if ( '' !== $email ) {
			$owner    = Owners::upsert( $email, $wants_pdf, $marketing );
			$owner_id = (int) $owner['id'];
		}

		return $this->enqueue( Scans::create( $target['url'], $target['domain'], $ip_hash, $owner_id, $wants_pdf ) );
	}

	/**
	 * Rescans a site from the dashboard. Ownership is checked on the object.
	 *
	 * @param int    $owner_id Signed-in owner.
	 * @param string $uuid     Scan to repeat.
	 * @param string $ip       Client address.
	 * @return array<string,mixed>|WP_Error
	 */
	public function rescan( int $owner_id, string $uuid, string $ip ) {
		$scan = Scans::find_by_uuid( $uuid );
		if ( null === $scan || (int) $scan['owner_id'] !== $owner_id ) {
			return new WP_Error( 'shr_not_found', __( 'Scan not found.', 'oldev-health-report' ), array( 'status' => 404 ) );
		}

		$ip_hash = ClientIp::hash( $ip );
		$target  = UrlGuard::normalize( (string) $scan['url'] );
		if ( is_wp_error( $target ) ) {
			$target->add_data( array( 'status' => 422 ) );
			return $target;
		}

		$limited = $this->check_ip_limits( $ip_hash );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$allowed = $this->check_capacity( $target['domain'], $ip_hash );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->enqueue( Scans::create( $target['url'], $target['domain'], $ip_hash, $owner_id, false ) );
	}

	/**
	 * Applies the per-IP windows.
	 *
	 * @param string $ip_hash Keyed IP hash.
	 * @return true|WP_Error
	 */
	private function check_ip_limits( string $ip_hash ) {
		$hour = Counters::hit( 'ip-hour|' . $ip_hash, HOUR_IN_SECONDS, Config::int( 'SHR_LIMIT_IP_HOUR', 5 ) );
		$day  = Counters::hit( 'ip-day|' . $ip_hash, DAY_IN_SECONDS, Config::int( 'SHR_LIMIT_IP_DAY', 20 ) );
		if ( $hour && $day ) {
			return true;
		}
		AbuseLog::record( 'ip_limited', $ip_hash );
		return new WP_Error( 'shr_ip_limited', __( 'Too many scans from your connection. Try again later.', 'oldev-health-report' ), array( 'status' => 429 ) );
	}

	/**
	 * Applies the per-domain cooldown, then the global daily cap.
	 *
	 * @param string $domain  Domain.
	 * @param string $ip_hash Keyed IP hash.
	 * @return true|WP_Error
	 */
	private function check_capacity( string $domain, string $ip_hash ) {
		$cooldown = Config::int( 'SHR_DOMAIN_COOLDOWN_MINUTES', 360 );
		if ( $cooldown > 0 && ! Counters::hit( 'domain|' . $domain, $cooldown * MINUTE_IN_SECONDS, 1 ) ) {
			AbuseLog::record( 'domain_cooldown', $ip_hash, $domain );
			return new WP_Error( 'shr_domain_cooldown', __( 'This domain was scanned recently. Try again later.', 'oldev-health-report' ), array( 'status' => 429 ) );
		}
		if ( ! Counters::spend_daily( 'scans', Config::int( 'SHR_DAILY_SCAN_CAP', 100 ) ) ) {
			AbuseLog::record( 'daily_scan_cap', $ip_hash, $domain );
			return new WP_Error( 'shr_daily_cap', __( 'The daily scan limit has been reached. Try again tomorrow.', 'oldev-health-report' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Queues a scan and builds the response.
	 *
	 * @param array<string,mixed> $scan Scan row.
	 * @return array<string,mixed>
	 */
	private function enqueue( array $scan ): array {
		as_enqueue_async_action( Dispatcher::ACTION, array( (int) $scan['id'] ), self::GROUP );

		return array(
			'uuid'       => $scan['uuid'],
			'status'     => $scan['status'],
			'report_url' => self::report_url( (string) $scan['uuid'] ),
		);
	}

	/**
	 * Builds the unguessable report URL.
	 *
	 * @param string $uuid Scan uuid.
	 * @return string
	 */
	public static function report_url( string $uuid ): string {
		return home_url( '/report/' . $uuid . '/' . Signature::report_sig( $uuid ) . '/' );
	}
}
