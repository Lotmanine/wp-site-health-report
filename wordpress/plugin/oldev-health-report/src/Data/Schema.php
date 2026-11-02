<?php
/**
 * Custom table definitions.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and dbDelta definitions.
 */
final class Schema {

	public const VERSION = '2';

	/**
	 * Returns a prefixed table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'shr_' . $name;
	}

	/**
	 * Creates or updates all tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$scans   = self::table( 'scans' );
		$finding = self::table( 'findings' );
		$owners  = self::table( 'owners' );
		$links   = self::table( 'magic_links' );
		$usage   = self::table( 'usage' );
		$limits  = self::table( 'limits' );
		$nonces  = self::table( 'nonces' );
		$abuse   = self::table( 'abuse_log' );
		$outbox  = self::table( 'outbox' );

		dbDelta(
			"CREATE TABLE {$scans} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				owner_id bigint(20) unsigned DEFAULT NULL,
				url varchar(2000) NOT NULL,
				domain varchar(253) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				overall_score tinyint(3) unsigned DEFAULT NULL,
				grade char(1) DEFAULT NULL,
				category_scores text DEFAULT NULL,
				summary text DEFAULT NULL,
				summary_source varchar(10) DEFAULT NULL,
				raw_result longtext DEFAULT NULL,
				failure_reason varchar(190) DEFAULT NULL,
				ip_hash char(32) NOT NULL DEFAULT '',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				wants_pdf tinyint(1) NOT NULL DEFAULT 0,
				pdf_sent_at datetime DEFAULT NULL,
				consent_at datetime NOT NULL,
				created_at datetime NOT NULL,
				completed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY owner_id (owner_id),
				KEY domain_created (domain(100),created_at),
				KEY status_created (status,created_at),
				KEY created_at (created_at)
			) {$charset};
			CREATE TABLE {$finding} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id bigint(20) unsigned NOT NULL,
				check_key varchar(40) NOT NULL,
				category varchar(20) NOT NULL,
				outcome varchar(10) NOT NULL,
				severity varchar(10) NOT NULL,
				weight tinyint(3) unsigned NOT NULL DEFAULT 0,
				rank_position smallint(5) unsigned NOT NULL DEFAULT 0,
				title varchar(190) NOT NULL,
				detail text DEFAULT NULL,
				fix text DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY scan_rank (scan_id,rank_position)
			) {$charset};
			CREATE TABLE {$owners} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				pdf_consent_at datetime DEFAULT NULL,
				marketing_consent_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email)
			) {$charset};
			CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY owner_id (owner_id),
				KEY expires_at (expires_at)
			) {$charset};
			CREATE TABLE {$usage} (
				day date NOT NULL,
				metric varchar(20) NOT NULL,
				used int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,metric)
			) {$charset};
			CREATE TABLE {$limits} (
				bucket char(40) NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				window_end bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (bucket),
				KEY window_end (window_end)
			) {$charset};
			CREATE TABLE {$nonces} (
				nonce char(32) NOT NULL,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (nonce),
				KEY expires_at (expires_at)
			) {$charset};
			CREATE TABLE {$abuse} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event varchar(40) NOT NULL,
				ip_hash char(32) NOT NULL DEFAULT '',
				domain varchar(253) NOT NULL DEFAULT '',
				detail varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_created (event,created_at),
				KEY created_at (created_at)
			) {$charset};
			CREATE TABLE {$outbox} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL,
				email_hash char(64) NOT NULL,
				payload longtext NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email_hash (email_hash),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'shr_schema_version', self::VERSION, false );
	}

	/**
	 * Tells whether the stored schema is older than this code. Activation does not run again on an
	 * already active plugin, so upgrades are detected on load.
	 *
	 * @return bool
	 */
	public static function is_outdated(): bool {
		return self::VERSION !== get_option( 'shr_schema_version' );
	}
}
