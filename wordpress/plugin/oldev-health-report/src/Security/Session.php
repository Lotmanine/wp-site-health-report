<?php
/**
 * Dashboard session built on a signed cookie.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Security;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Visitors are not WordPress users, so the dashboard keeps its own short signed session.
 */
final class Session {

	public const COOKIE = 'shr_session';

	public const LIFETIME = 43200;

	/**
	 * Current owner, set when a session starts inside this request.
	 *
	 * @var int|null
	 */
	private static ?int $request_owner = null;

	/**
	 * Starts a session.
	 *
	 * @param int $owner_id Owner id.
	 * @return void
	 */
	public static function start( int $owner_id ): void {
		$expires                 = time() + self::LIFETIME;
		$value                   = $owner_id . '.' . $expires;
		$cookie                  = $value . '.' . self::mac( $value );
		self::$request_owner     = $owner_id;
		$_COOKIE[ self::COOKIE ] = $cookie;

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$cookie,
				array(
					'expires'  => $expires,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Ends the session.
	 *
	 * @return void
	 */
	public static function end(): void {
		self::$request_owner = null;
		unset( $_COOKIE[ self::COOKIE ] );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				'',
				array(
					'expires'  => time() - 3600,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Returns the signed-in owner id, or 0.
	 *
	 * @return int
	 */
	public static function owner_id(): int {
		if ( null !== self::$request_owner ) {
			return self::$request_owner;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authenticated by its MAC below.
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$parts  = explode( '.', $cookie );
		if ( 3 !== count( $parts ) ) {
			return 0;
		}
		list( $owner, $expires, $mac ) = $parts;
		if ( ! hash_equals( self::mac( $owner . '.' . $expires ), $mac ) || (int) $expires < time() ) {
			return 0;
		}
		return (int) $owner;
	}

	/**
	 * Session-bound token for write requests.
	 *
	 * @return string
	 */
	public static function csrf_token(): string {
		$owner = self::owner_id();
		return 0 === $owner ? '' : self::mac( 'csrf|' . $owner );
	}

	/**
	 * Verifies a write token in constant time.
	 *
	 * @param string $token Token from the request.
	 * @return bool
	 */
	public static function verify_csrf( string $token ): bool {
		$expected = self::csrf_token();
		return '' !== $expected && hash_equals( $expected, $token );
	}

	/**
	 * Clears request state, used by tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$request_owner = null;
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Signs a value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function mac( string $value ): string {
		return hash_hmac( 'sha256', 'session|' . $value, Config::app_secret() );
	}
}
