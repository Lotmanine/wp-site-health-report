<?php
/**
 * Verifies and stores signed scan results.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scan;

use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Scoring\Evaluator;
use Oldev\HealthReport\Scoring\Scorer;
use Oldev\HealthReport\Security\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Handles POST /callback. Checks run in order of cost, and nothing is parsed before the signature passes.
 */
final class CallbackHandler {

	public const FINISH_ACTION = 'shr_finish_scan';

	private const MAX_BODY = 1048576;

	/**
	 * Processes a callback.
	 *
	 * @param string $raw       Raw body.
	 * @param string $signature Signature header.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function handle( string $raw, string $signature ): array {
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return self::reply( 413, 'too_large' );
		}
		if ( ! Signature::verify( $raw, $signature ) ) {
			AbuseLog::record( 'bad_signature', '', '', 'callback' );
			return self::reply( 401, 'bad_signature' );
		}

		$message = json_decode( $raw, true );
		if ( ! is_array( $message ) || 'scan.result' !== ( $message['type'] ?? '' ) || 1 !== ( $message['v'] ?? 0 ) ) {
			return self::reply( 400, 'bad_message' );
		}
		if ( ! Signature::is_fresh( (int) ( $message['issued_at'] ?? 0 ) ) ) {
			AbuseLog::record( 'stale_callback' );
			return self::reply( 401, 'stale' );
		}

		$nonce = (string) ( $message['nonce'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $nonce ) ) {
			return self::reply( 400, 'bad_nonce' );
		}
		if ( ! Counters::claim_nonce( $nonce, 2 * Signature::MAX_AGE ) ) {
			AbuseLog::record( 'replayed_callback' );
			return self::reply( 409, 'replayed' );
		}

		$scan = Scans::find_by_uuid( (string) ( $message['scan_id'] ?? '' ) );
		if ( null === $scan ) {
			return self::reply( 404, 'unknown_scan' );
		}

		$stored = 'complete' === ( $message['status'] ?? '' )
			? $this->complete( $scan, $message )
			: $this->fail( $scan, (string) ( $message['error'] ?? 'pipeline_failed' ) );

		return $stored ? self::reply( 200, 'stored' ) : self::reply( 409, 'already_finished' );
	}

	/**
	 * Stores a finished scan and queues the follow-up work.
	 *
	 * @param array<string,mixed> $scan    Scan row.
	 * @param array<string,mixed> $message Verified message.
	 * @return bool
	 */
	private function complete( array $scan, array $message ): bool {
		$findings = Scorer::rank( Evaluator::evaluate( $message ) );
		$scores   = Scorer::score( $findings );

		$finished = Scans::finish(
			(int) $scan['id'],
			array(
				'status'          => Scans::COMPLETE,
				'overall_score'   => $scores['overall'],
				'grade'           => $scores['grade'],
				'category_scores' => wp_json_encode( $scores['categories'] ),
				'raw_result'      => wp_json_encode( $message ),
			)
		);
		if ( ! $finished ) {
			return false;
		}

		Scans::save_findings( (int) $scan['id'], $findings );
		as_enqueue_async_action( self::FINISH_ACTION, array( (int) $scan['id'] ), ScanService::GROUP );
		return true;
	}

	/**
	 * Marks a scan failed.
	 *
	 * @param array<string,mixed> $scan   Scan row.
	 * @param string              $reason Failure reason.
	 * @return bool
	 */
	private function fail( array $scan, string $reason ): bool {
		$finished = Scans::finish(
			(int) $scan['id'],
			array(
				'status'         => Scans::FAILED,
				'failure_reason' => substr( sanitize_key( $reason ), 0, 190 ),
			)
		);
		if ( $finished ) {
			AbuseLog::record( 'scan_failed', '', (string) $scan['domain'], sanitize_key( $reason ) );
		}
		return $finished;
	}

	/**
	 * Builds a reply.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Result code.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function reply( int $status, string $code ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'ok'   => 200 === $status,
				'code' => $code,
			),
		);
	}
}
