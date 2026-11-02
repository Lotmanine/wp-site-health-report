<?php
/**
 * Mail transport through n8n.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Mail;

use Oldev\HealthReport\Crm\N8nMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Hands a message to n8n, which sends it with the same Brevo setup as the deliverability audit. The
 * caps in MailService run before this, so n8n never sends more than the daily allowance.
 */
final class N8nMailer implements Mailer {

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
		return N8nMessage::post(
			'mail.send',
			array(
				'to'         => $to,
				'subject'    => $subject,
				'html'       => $html,
				'attachment' => null === $attachment ? null : array(
					'name'    => $attachment['name'],
					'content' => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary attachment inside JSON.
				),
			)
		);
	}
}
