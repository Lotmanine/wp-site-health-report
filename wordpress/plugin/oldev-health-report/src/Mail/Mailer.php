<?php
/**
 * Mail transport contract.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one message.
 */
interface Mailer {

	/**
	 * Sends a message.
	 *
	 * @param string                                 $to         Recipient.
	 * @param string                                 $subject    Subject.
	 * @param string                                 $html       HTML body.
	 * @param array{name:string,content:string}|null $attachment Attachment with raw bytes.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $html, ?array $attachment = null ): bool;
}
