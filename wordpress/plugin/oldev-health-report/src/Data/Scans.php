<?php
/**
 * Scan and finding storage.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for scans and their findings.
 */
final class Scans {

	public const QUEUED     = 'queued';
	public const DISPATCHED = 'dispatched';
	public const COMPLETE   = 'complete';
	public const FAILED     = 'failed';

	/**
	 * Inserts a scan.
	 *
	 * @param string   $url       Normalised URL.
	 * @param string   $domain    Domain.
	 * @param string   $ip_hash   Keyed IP hash.
	 * @param int|null $owner_id  Owner id.
	 * @param bool     $wants_pdf Whether the visitor consented to the PDF email.
	 * @return array<string,mixed>
	 */
	public static function create( string $url, string $domain, string $ip_hash, ?int $owner_id, bool $wants_pdf ): array {
		global $wpdb;
		$uuid = wp_generate_uuid4();
		$now  = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			Schema::table( 'scans' ),
			array(
				'uuid'       => $uuid,
				'owner_id'   => $owner_id,
				'url'        => $url,
				'domain'     => $domain,
				'status'     => self::QUEUED,
				'ip_hash'    => $ip_hash,
				'wants_pdf'  => $wants_pdf ? 1 : 0,
				'consent_at' => $now,
				'created_at' => $now,
			)
		);
		return (array) self::find_by_uuid( $uuid );
	}

	/**
	 * Finds a scan by id.
	 *
	 * @param int $id Scan id.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'scans' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Finds a scan by uuid.
	 *
	 * @param string $uuid Scan uuid.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', Schema::table( 'scans' ), $uuid ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Updates scan columns.
	 *
	 * @param int                 $id     Scan id.
	 * @param array<string,mixed> $fields Columns.
	 * @return void
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'scans' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Moves a scan to a final state, once. A finished scan can never be overwritten.
	 *
	 * @param int                 $id     Scan id.
	 * @param array<string,mixed> $fields Columns, including the final status.
	 * @return bool False when another request already finished it.
	 */
	public static function finish( int $id, array $fields ): bool {
		global $wpdb;
		$table   = Schema::table( 'scans' );
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, completed_at = %s WHERE id = %d AND status IN (%s, %s)',
				$table,
				(string) $fields['status'],
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				self::QUEUED,
				self::DISPATCHED
			)
		);
		if ( 1 !== (int) $claimed ) {
			return false;
		}
		unset( $fields['status'] );
		if ( array() !== $fields ) {
			self::update( $id, $fields );
		}
		return true;
	}

	/**
	 * Returns an owner's scans, newest first.
	 *
	 * @param int $owner_id Owner id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_owner( int $owner_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, uuid, url, domain, status, overall_score, grade, category_scores, created_at, completed_at
				FROM %i WHERE owner_id = %d ORDER BY id DESC LIMIT 100',
				Schema::table( 'scans' ),
				$owner_id
			),
			ARRAY_A
		);
	}

	/**
	 * Returns scans stuck in an active state since before a cutoff.
	 *
	 * @param string $cutoff GMT datetime.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stuck_since( string $cutoff ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, domain FROM %i WHERE status IN (%s, %s) AND created_at < %s LIMIT 100',
				Schema::table( 'scans' ),
				self::QUEUED,
				self::DISPATCHED,
				$cutoff
			),
			ARRAY_A
		);
	}

	/**
	 * Replaces a scan's findings.
	 *
	 * @param int                            $scan_id  Scan id.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return void
	 */
	public static function save_findings( int $scan_id, array $findings ): void {
		global $wpdb;
		$table = Schema::table( 'findings' );
		$wpdb->delete( $table, array( 'scan_id' => $scan_id ) );
		foreach ( $findings as $finding ) {
			$wpdb->insert(
				$table,
				array(
					'scan_id'       => $scan_id,
					'check_key'     => $finding['check_key'],
					'category'      => $finding['category'],
					'outcome'       => $finding['outcome'],
					'severity'      => $finding['severity'],
					'weight'        => $finding['weight'],
					'rank_position' => $finding['rank_position'],
					'title'         => $finding['title'],
					'detail'        => $finding['detail'],
					'fix'           => $finding['fix'],
				)
			);
		}
	}

	/**
	 * Returns a scan's findings in rank order.
	 *
	 * @param int $scan_id Scan id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function findings( int $scan_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE scan_id = %d ORDER BY rank_position ASC', Schema::table( 'findings' ), $scan_id ),
			ARRAY_A
		);
	}

	/**
	 * Deletes one scan with its findings.
	 *
	 * @param int $id Scan id.
	 * @return void
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'findings' ), array( 'scan_id' => $id ) );
		$wpdb->delete( Schema::table( 'scans' ), array( 'id' => $id ) );
	}

	/**
	 * Deletes all of an owner's scans.
	 *
	 * @param int $owner_id Owner id.
	 * @return void
	 */
	public static function delete_for_owner( int $owner_id ): void {
		global $wpdb;
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE owner_id = %d', Schema::table( 'scans' ), $owner_id ) );
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
	}

	/**
	 * Deletes scans created before a cutoff.
	 *
	 * @param string $cutoff GMT datetime.
	 * @return int Scans deleted.
	 */
	public static function delete_older_than( string $cutoff ): int {
		global $wpdb;
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE created_at < %s LIMIT 1000', Schema::table( 'scans' ), $cutoff )
		);
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * Counts scans by status for today.
	 *
	 * @return array<string,int>
	 */
	public static function status_counts_today(): array {
		global $wpdb;
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY status',
				Schema::table( 'scans' ),
				gmdate( 'Y-m-d 00:00:00' )
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}
}
