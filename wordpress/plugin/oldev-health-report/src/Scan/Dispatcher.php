<?php
/**
 * Sends signed jobs to n8n.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scan;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Security\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for job delivery, with retries and backoff.
 */
final class Dispatcher {

	public const ACTION = 'shr_dispatch_scan';

	public const MAX_ATTEMPTS = 3;

	/**
	 * Builds the signed job body.
	 *
	 * @param array<string,mixed> $scan Scan row.
	 * @return string
	 */
	public static function build_job( array $scan ): string {
		return (string) wp_json_encode(
			array(
				'v'            => 1,
				'type'         => 'scan.job',
				'scan_id'      => $scan['uuid'],
				'url'          => $scan['url'],
				'domain'       => $scan['domain'],
				'callback_url' => Config::callback_url(),
				'scanner_url'  => rtrim( Config::string( 'SHR_SCANNER_URL' ), '/' ),
				'issued_at'    => time(),
				'nonce'        => bin2hex( random_bytes( 16 ) ),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Delivers a job.
	 *
	 * @param int $scan_id Scan id.
	 * @return void
	 */
	public function dispatch( int $scan_id ): void {
		$scan = Scans::find( $scan_id );
		if ( null === $scan || Scans::QUEUED !== $scan['status'] ) {
			return;
		}

		$attempts = (int) $scan['attempts'] + 1;
		Scans::update( $scan_id, array( 'attempts' => $attempts ) );

		$url  = Config::string( 'SHR_N8N_WEBHOOK_URL' );
		$body = self::build_job( $scan );
		$sig  = Signature::sign( $body );

		$delivered = false;
		if ( '' !== $url && '' !== $sig ) {
			$response  = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'    => 'application/json',
						Signature::HEADER => $sig,
					),
					'body'    => $body,
				)
			);
			$code      = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$delivered = $code >= 200 && $code < 300;
		}

		if ( $delivered ) {
			Scans::update( $scan_id, array( 'status' => Scans::DISPATCHED ) );
			return;
		}

		if ( $attempts < self::MAX_ATTEMPTS ) {
			as_schedule_single_action( time() + 30 * $attempts, self::ACTION, array( $scan_id ), ScanService::GROUP );
			return;
		}

		Scans::finish(
			$scan_id,
			array(
				'status'         => Scans::FAILED,
				'failure_reason' => 'dispatch_failed',
			)
		);
		AbuseLog::record( 'dispatch_failed', '', (string) $scan['domain'] );
	}
}
