<?php
/**
 * Capped email sending.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Mail;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;

defined( 'ABSPATH' ) || exit;

/**
 * Every project email passes through here, so the caps cannot be bypassed.
 */
final class MailService {

	/**
	 * Wires the transport.
	 *
	 * @param Mailer $mailer Transport.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * Sends the PDF report.
	 *
	 * @param string              $to   Recipient.
	 * @param array<string,mixed> $scan Scan row.
	 * @param string              $pdf  PDF bytes.
	 * @return bool
	 */
	public function send_report( string $to, array $scan, string $pdf ): bool {
		$domain  = (string) $scan['domain'];
		$subject = sprintf(
			/* translators: %s: domain. */
			__( 'Your website health report for %s', 'oldev-health-report' ),
			$domain
		);
		$html = sprintf(
			'<p>%s</p><p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: domain, 2: score out of 100. */
					__( 'Your report for %1$s is attached. The site scored %2$d out of 100.', 'oldev-health-report' ),
					$domain,
					(int) $scan['overall_score']
				)
			),
			esc_html__( 'You received this because you asked for it on the scan form. Your data is deleted after 30 days.', 'oldev-health-report' )
		);

		return $this->send(
			$to,
			$subject,
			$html,
			array(
				'name'    => 'health-report-' . sanitize_file_name( $domain ) . '.pdf',
				'content' => $pdf,
			)
		);
	}

	/**
	 * Sends a dashboard sign-in link.
	 *
	 * @param string $to   Recipient.
	 * @param string $link Link.
	 * @return bool
	 */
	public function send_magic_link( string $to, string $link ): bool {
		$html = sprintf(
			'<p>%s</p><p><a href="%s">%s</a></p><p>%s</p>',
			esc_html__( 'Use this link to open your scan history. It works once and expires in 30 minutes.', 'oldev-health-report' ),
			esc_url( $link ),
			esc_html__( 'Open my dashboard', 'oldev-health-report' ),
			esc_html__( 'If you did not ask for this, ignore this email.', 'oldev-health-report' )
		);
		return $this->send( $to, __( 'Your Website Health Report sign-in link', 'oldev-health-report' ), $html );
	}

	/**
	 * Applies the caps, then sends.
	 *
	 * @param string                                 $to         Recipient.
	 * @param string                                 $subject    Subject.
	 * @param string                                 $html       HTML body.
	 * @param array{name:string,content:string}|null $attachment Attachment.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $html, ?array $attachment = null ): bool {
		$address_ok = Counters::hit( 'email|' . strtolower( $to ), DAY_IN_SECONDS, Config::int( 'SHR_LIMIT_EMAIL_ADDRESS_DAY', 3 ) );
		if ( ! $address_ok ) {
			AbuseLog::record( 'email_address_limited' );
			return false;
		}
		if ( ! Counters::spend_daily( 'emails', Config::daily_email_cap() ) ) {
			AbuseLog::record( 'daily_email_cap' );
			return false;
		}
		$sent = $this->mailer->send( $to, $subject, $html, $attachment );
		if ( ! $sent ) {
			AbuseLog::record( 'email_failed' );
		}
		return $sent;
	}
}
