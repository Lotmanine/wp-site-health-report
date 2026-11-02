<?php
/**
 * Client address resolution.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Security;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the client IP without trusting spoofable headers by default.
 */
final class ClientIp {

	/**
	 * Returns the client IP. With SHR_TRUSTED_PROXY_HOPS set, reads X-Forwarded-For from the right,
	 * because only the entries appended by trusted proxies can be believed.
	 *
	 * @return string
	 */
	public static function current(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$hops   = Config::int( 'SHR_TRUSTED_PROXY_HOPS', 0 );

		if ( $hops > 0 && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
			$forwarded = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = $forwarded[ count( $forwarded ) - $hops ] ?? '';
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}

	/**
	 * Keyed hash of an IP. Raw addresses are never stored.
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	public static function hash( string $ip ): string {
		return substr( hash_hmac( 'sha256', 'ip|' . $ip, Config::app_secret() ), 0, 32 );
	}
}
