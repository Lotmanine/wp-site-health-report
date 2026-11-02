<?php
/**
 * HMAC signing for machine messages and report URLs.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Security;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies raw bytes. An empty secret always fails closed.
 */
final class Signature {

	public const HEADER = 'X-SHR-Signature';

	/**
	 * Accepted clock difference on signed messages, in seconds.
	 */
	public const MAX_AGE = 300;

	/**
	 * Signs a raw body.
	 *
	 * @param string $body Raw bytes.
	 * @return string Header value, empty when no secret is configured.
	 */
	public static function sign( string $body ): string {
		$secret = Config::hmac_secret();
		if ( '' === $secret ) {
			return '';
		}
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Verifies a signature header in constant time.
	 *
	 * @param string $body   Raw bytes as received.
	 * @param string $header Header value as received.
	 * @return bool
	 */
	public static function verify( string $body, string $header ): bool {
		$expected = self::sign( $body );
		return '' !== $expected && hash_equals( $expected, trim( $header ) );
	}

	/**
	 * Checks that a message timestamp is inside the accepted window.
	 *
	 * @param int $issued_at Unix timestamp from the message.
	 * @return bool
	 */
	public static function is_fresh( int $issued_at ): bool {
		return abs( time() - $issued_at ) <= self::MAX_AGE;
	}

	/**
	 * Derives the URL signature for a scan uuid.
	 *
	 * @param string $uuid Scan uuid.
	 * @return string URL-safe signature, empty when no secret is configured.
	 */
	public static function report_sig( string $uuid ): string {
		$secret = Config::app_secret();
		if ( '' === $secret ) {
			return '';
		}
		$raw = hash_hmac( 'sha256', 'report|' . $uuid, $secret, true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of a MAC.
	}

	/**
	 * Verifies a report URL signature in constant time.
	 *
	 * @param string $uuid Scan uuid.
	 * @param string $sig  Signature from the URL.
	 * @return bool
	 */
	public static function verify_report_sig( string $uuid, string $sig ): bool {
		$expected = self::report_sig( $uuid );
		return '' !== $expected && hash_equals( $expected, $sig );
	}
}
