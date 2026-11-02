<?php
/**
 * Report owners and magic links.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for people who left an email address.
 */
final class Owners {

	public const LINK_LIFETIME = 1800;

	/**
	 * Creates an owner or updates consent. Consent is only ever added here, never removed by a scan.
	 *
	 * @param string $email     Lowercase address.
	 * @param bool   $pdf       PDF consent.
	 * @param bool   $marketing Marketing consent.
	 * @return array<string,mixed>
	 */
	public static function upsert( string $email, bool $pdf, bool $marketing ): array {
		global $wpdb;
		$table = Schema::table( 'owners' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (email, pdf_consent_at, marketing_consent_at, created_at)
				VALUES (%s, IF(%d, %s, NULL), IF(%d, %s, NULL), %s)
				ON DUPLICATE KEY UPDATE
					pdf_consent_at = COALESCE(VALUES(pdf_consent_at), pdf_consent_at),
					marketing_consent_at = COALESCE(VALUES(marketing_consent_at), marketing_consent_at)',
				$table,
				$email,
				$pdf ? 1 : 0,
				$now,
				$marketing ? 1 : 0,
				$now,
				$now
			)
		);

		return (array) self::find_by_email( $email );
	}

	/**
	 * Finds an owner by email.
	 *
	 * @param string $email Lowercase address.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_email( string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', Schema::table( 'owners' ), $email ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Finds an owner by id.
	 *
	 * @param int $id Owner id.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'owners' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Issues a magic link token. Only its hash is stored.
	 *
	 * @param int $owner_id Owner id.
	 * @return string Raw token.
	 */
	public static function issue_link( int $owner_id ): string {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		$now   = time();
		$wpdb->insert(
			Schema::table( 'magic_links' ),
			array(
				'owner_id'   => $owner_id,
				'token_hash' => hash( 'sha256', $token ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::LINK_LIFETIME ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		return $token;
	}

	/**
	 * Consumes a magic link. The UPDATE makes single use hold under concurrent requests.
	 *
	 * @param string $token Raw token.
	 * @return int Owner id, or 0.
	 */
	public static function consume_link( string $token ): int {
		global $wpdb;
		$table = Schema::table( 'magic_links' );
		$hash  = hash( 'sha256', $token );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET used_at = %s WHERE token_hash = %s AND used_at IS NULL AND expires_at > %s',
				$table,
				$now,
				$hash,
				$now
			)
		);
		if ( 1 !== (int) $claimed ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_id FROM %i WHERE token_hash = %s', $table, $hash ) );
	}

	/**
	 * Deletes an owner and their links.
	 *
	 * @param int $id Owner id.
	 * @return void
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'magic_links' ), array( 'owner_id' => $id ) );
		$wpdb->delete( Schema::table( 'owners' ), array( 'id' => $id ) );
	}

	/**
	 * Returns owners with no scans left.
	 *
	 * @param string $cutoff Keep owners created after this GMT datetime.
	 * @return array<int,array<string,mixed>>
	 */
	public static function without_scans( string $cutoff ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.id, o.email FROM %i o LEFT JOIN %i s ON s.owner_id = o.id
				WHERE s.id IS NULL AND o.created_at < %s LIMIT 500',
				Schema::table( 'owners' ),
				Schema::table( 'scans' ),
				$cutoff
			),
			ARRAY_A
		);
	}

	/**
	 * Deletes expired magic links.
	 *
	 * @return void
	 */
	public static function purge_links(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', Schema::table( 'magic_links' ), gmdate( 'Y-m-d H:i:s' ) ) );
	}
}
