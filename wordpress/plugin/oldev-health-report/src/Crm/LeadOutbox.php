<?php
/**
 * Outbox for lead events bound for n8n.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Crm;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Schema;
use Oldev\HealthReport\Scan\ScanService;

defined( 'ABSPATH' ) || exit;

/**
 * Lead events wait here until n8n confirms them, then the row is deleted. Action Scheduler only
 * carries the row id, so no email address outlives an erasure in its logs.
 */
final class LeadOutbox {

	public const ACTION = 'shr_deliver_lead';

	public const MAX_ATTEMPTS = 6;

	/**
	 * Queues an event and schedules its delivery.
	 *
	 * @param string              $type  Event type.
	 * @param string              $email Address the event is about.
	 * @param array<string,mixed> $data  Event data.
	 * @return int Row id.
	 */
	public static function push( string $type, string $email, array $data ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			Schema::table( 'outbox' ),
			array(
				'type'       => $type,
				'email_hash' => self::hash( $email ),
				'payload'    => (string) wp_json_encode( $data ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		self::schedule( $id, 0 );
		return $id;
	}

	/**
	 * Drops events that have not left yet for an address, so an erased address is not sent again.
	 *
	 * @param string $email Address.
	 * @return void
	 */
	public static function forget( string $email ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'DELETE FROM %i WHERE email_hash = %s AND type <> %s',
				Schema::table( 'outbox' ),
				self::hash( $email ),
				'lead.erase'
			)
		);
	}

	/**
	 * Delivers one event, retrying with backoff on failure.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function deliver( int $id ): void {
		$row = self::find( $id );
		if ( null === $row ) {
			return;
		}

		$data = json_decode( (string) $row['payload'], true );
		if ( is_array( $data ) && N8nMessage::post( (string) $row['type'], $data ) ) {
			self::delete( $id );
			return;
		}

		$attempts = (int) $row['attempts'] + 1;
		global $wpdb;
		$wpdb->update( Schema::table( 'outbox' ), array( 'attempts' => $attempts ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		if ( $attempts < self::MAX_ATTEMPTS ) {
			self::schedule( $id, $attempts );
			return;
		}
		AbuseLog::record( 'lead_delivery_failed', '', '', (string) $row['type'] );
	}

	/**
	 * Requeues events that ran out of attempts. Called by the daily retention job, so an erasure keeps
	 * being retried until n8n confirms it.
	 *
	 * @return int Rows requeued.
	 */
	public static function requeue_stalled(): int {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT id FROM %i WHERE attempts >= %d', Schema::table( 'outbox' ), self::MAX_ATTEMPTS )
		);
		foreach ( $ids as $id ) {
			$wpdb->update( Schema::table( 'outbox' ), array( 'attempts' => 0 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			self::schedule( (int) $id, 0 );
		}
		return count( $ids );
	}

	/**
	 * Deletes lead events older than the retention period. Erase events stay until delivered.
	 *
	 * @param string $cutoff Datetime in UTC.
	 * @return void
	 */
	public static function purge( string $cutoff ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s AND type <> %s', Schema::table( 'outbox' ), $cutoff, 'lead.erase' )
		);
	}

	/**
	 * Lists pending events, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending(): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', Schema::table( 'outbox' ) ),
			ARRAY_A
		);
	}

	/**
	 * Builds the lead row sent to the spreadsheet.
	 *
	 * @param array<string,mixed>            $owner    Owner row.
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return array<string,mixed>
	 */
	public static function lead_data( array $owner, array $scan, array $findings ): array {
		$failed = array();
		$fixes  = array();
		foreach ( $findings as $finding ) {
			if ( 'fail' === $finding['outcome'] ) {
				$failed[ (string) $finding['category'] ] = true;
			}
			if ( 'pass' !== $finding['outcome'] && count( $fixes ) < 3 ) {
				$fixes[] = (string) $finding['title'];
			}
		}

		// One row per address in the spreadsheet, refreshed by each scan, so a row lives exactly as long as
		// the address does here.
		return array(
			'email'             => strtolower( trim( (string) $owner['email'] ) ),
			'updated_at'        => gmdate( 'c' ),
			'report_id'         => (string) $scan['uuid'],
			'domain'            => (string) $scan['domain'],
			'score'             => (int) $scan['overall_score'],
			'grade'             => (string) $scan['grade'],
			'failed_checks'     => implode( ', ', array_keys( $failed ) ),
			'top_fixes'         => implode( ' | ', $fixes ),
			'pdf_requested'     => ! empty( $scan['wants_pdf'] ),
			'marketing_consent' => ! empty( $owner['marketing_consent_at'] ),
			'report_url'        => ScanService::report_url( (string) $scan['uuid'] ),
		);
	}

	/**
	 * Keyed hash of an address, so pending rows can be found without reading payloads.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	private static function hash( string $email ): string {
		return hash_hmac( 'sha256', 'outbox|' . strtolower( trim( $email ) ), Config::app_secret() );
	}

	/**
	 * Schedules a delivery attempt.
	 *
	 * @param int $id       Row id.
	 * @param int $attempts Attempts so far.
	 * @return void
	 */
	private static function schedule( int $id, int $attempts ): void {
		as_schedule_single_action( time() + ( 0 === $attempts ? 0 : 60 * ( 2 ** $attempts ) ), self::ACTION, array( $id ), ScanService::GROUP );
	}

	/**
	 * Reads a row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	private static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'outbox' ), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Deletes a row.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	private static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'outbox' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}
}
