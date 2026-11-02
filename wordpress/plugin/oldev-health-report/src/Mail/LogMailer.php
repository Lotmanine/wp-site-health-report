<?php
/**
 * Log-only transport, used while BREVO_API_KEY is empty.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Mail;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Writes messages to a directory outside the web root instead of sending them.
 */
final class LogMailer implements Mailer {

	/**
	 * Stores a message.
	 *
	 * @param string                                 $to         Recipient.
	 * @param string                                 $subject    Subject.
	 * @param string                                 $html       HTML body.
	 * @param array{name:string,content:string}|null $attachment Attachment with raw bytes.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $html, ?array $attachment = null ): bool {
		$dir = Config::string( 'SHR_MAIL_LOG_DIR', sys_get_temp_dir() . '/shr-mail' );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$stem = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
		if ( null !== $attachment ) {
			file_put_contents( $dir . '/' . $stem . '.pdf', $attachment['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local stub outside WP_Filesystem scope.
		}
		$written = file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local stub outside WP_Filesystem scope.
			$dir . '/' . $stem . '.json',
			(string) wp_json_encode(
				array(
					'to'      => $to,
					'subject' => $subject,
					'html'    => $html,
					'pdf'     => null !== $attachment ? $stem . '.pdf' : null,
				)
			)
		);
		return false !== $written;
	}
}
