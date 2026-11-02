<?php
/**
 * Cloudflare Turnstile server-side verification.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Security;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies tokens against siteverify. Any failure, including a network error, rejects the token.
 */
final class Turnstile {

	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Verifies a Turnstile token.
	 *
	 * @param string $token Token from the widget.
	 * @param string $ip    Client address.
	 * @return bool
	 */
	public static function verify( string $token, string $ip ): bool {
		$secret = Config::string( 'SHR_TURNSTILE_SECRET_KEY' );
		if ( '' === $secret || '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && true === ( $data['success'] ?? false );
	}
}
