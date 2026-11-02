<?php
/**
 * Environment-backed configuration.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport;

defined( 'ABSPATH' ) || exit;

/**
 * Reads settings from environment variables. Secrets never live in the database.
 */
final class Config {

	/**
	 * Hard ceiling on project emails per day. Brevo's allowance is shared with the main site.
	 */
	public const EMAIL_CAP_CEILING = 50;

	/**
	 * Reads a string setting.
	 *
	 * @param string $name     Variable name.
	 * @param string $fallback Value when unset or empty.
	 * @return string
	 */
	public static function string( string $name, string $fallback = '' ): string {
		$value = getenv( $name );
		return ( false === $value || '' === $value ) ? $fallback : (string) $value;
	}

	/**
	 * Reads an integer setting. Negative values are treated as zero.
	 *
	 * @param string $name     Variable name.
	 * @param int    $fallback Value when unset, empty or not numeric.
	 * @return int
	 */
	public static function int( string $name, int $fallback ): int {
		$value = self::string( $name );
		return is_numeric( $value ) ? max( 0, (int) $value ) : $fallback;
	}

	/**
	 * Shared HMAC secret for the n8n exchange.
	 *
	 * @return string
	 */
	public static function hmac_secret(): string {
		return self::string( 'SHR_HMAC_SECRET' );
	}

	/**
	 * Separate key for hashing IPs, signing report URLs and sessions.
	 *
	 * @return string
	 */
	public static function app_secret(): string {
		return self::string( 'SHR_APP_SECRET' );
	}

	/**
	 * Global daily email cap, clamped to the shared-allowance ceiling.
	 *
	 * @return int
	 */
	public static function daily_email_cap(): int {
		return min( self::EMAIL_CAP_CEILING, self::int( 'SHR_DAILY_EMAIL_CAP', self::EMAIL_CAP_CEILING ) );
	}

	/**
	 * Whether the Claude summary can run at all.
	 *
	 * @return bool
	 */
	public static function ai_enabled(): bool {
		return '' !== self::string( 'ANTHROPIC_API_KEY' ) && '' !== self::string( 'SHR_AI_MODEL' );
	}

	/**
	 * Base URL for callbacks. Falls back to the site REST URL.
	 *
	 * @return string
	 */
	public static function callback_url(): string {
		$base = self::string( 'SHR_CALLBACK_BASE' );
		if ( '' === $base ) {
			return rest_url( 'shr/v1/callback' );
		}
		return rtrim( $base, '/' ) . '/wp-json/shr/v1/callback';
	}
}
