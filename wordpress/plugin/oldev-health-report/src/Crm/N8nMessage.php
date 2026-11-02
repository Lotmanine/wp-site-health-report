<?php
/**
 * Signed messages for the n8n lead and mail path.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Crm;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Security\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and posts one signed message. The body carries a fresh timestamp and nonce, so n8n can
 * reject stale and replayed messages.
 */
final class N8nMessage {

	public const TYPES = array( 'lead.upsert', 'lead.erase', 'mail.send' );

	/**
	 * Builds the raw body.
	 *
	 * @param string              $type Message type.
	 * @param array<string,mixed> $data Message data.
	 * @return string
	 */
	public static function build( string $type, array $data ): string {
		return (string) wp_json_encode(
			array(
				'v'         => 1,
				'type'      => $type,
				'issued_at' => time(),
				'nonce'     => bin2hex( random_bytes( 16 ) ),
				'data'      => $data,
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Posts a message.
	 *
	 * @param string              $type Message type.
	 * @param array<string,mixed> $data Message data.
	 * @return bool True on a 2xx answer.
	 */
	public static function post( string $type, array $data ): bool {
		$url  = Config::leads_url();
		$body = self::build( $type, $data );
		$sig  = Signature::sign( $body );
		if ( '' === $url || '' === $sig ) {
			return false;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'    => 'application/json',
					Signature::HEADER => $sig,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
