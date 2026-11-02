<?php
/**
 * Brevo transactional transport.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Mail;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the Brevo API. oldev.site publishes DMARC p=reject, so the sender stays fixed to the
 * address on the domain authenticated in Brevo.
 */
final class BrevoMailer implements Mailer {

	public const SENDER_EMAIL = 'lotfi@oldev.site';

	private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Sends a message.
	 *
	 * @param string                                 $to         Recipient.
	 * @param string                                 $subject    Subject.
	 * @param string                                 $html       HTML body.
	 * @param array{name:string,content:string}|null $attachment Attachment with raw bytes.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $html, ?array $attachment = null ): bool {
		$payload = array(
			'sender'      => array(
				'name'  => 'OLDEV',
				'email' => self::SENDER_EMAIL,
			),
			'to'          => array( array( 'email' => $to ) ),
			'subject'     => $subject,
			'htmlContent' => $html,
		);
		if ( null !== $attachment ) {
			$payload['attachment'] = array(
				array(
					'name'    => $attachment['name'],
					'content' => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Brevo requires base64 attachments.
				),
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array(
					'api-key'      => Config::string( 'BREVO_API_KEY' ),
					'content-type' => 'application/json',
					'accept'       => 'application/json',
				),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
