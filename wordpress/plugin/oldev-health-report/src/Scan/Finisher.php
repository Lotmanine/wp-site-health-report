<?php
/**
 * Post-scan work: summary, PDF email and CRM contact.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scan;

use Oldev\HealthReport\Crm\CrmGateway;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Mail\MailService;
use Oldev\HealthReport\Report\PdfRenderer;
use Oldev\HealthReport\Summary\SummaryService;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback that runs after a result is stored.
 */
final class Finisher {

	/**
	 * Wires collaborators.
	 *
	 * @param SummaryService $summary Summary service.
	 * @param MailService    $mail    Mail service.
	 * @param CrmGateway     $crm     CRM gateway.
	 */
	public function __construct(
		private SummaryService $summary,
		private MailService $mail,
		private CrmGateway $crm
	) {}

	/**
	 * Runs the follow-up steps. Each step checks its own consent.
	 *
	 * @param int $scan_id Scan id.
	 * @return void
	 */
	public function finish( int $scan_id ): void {
		$scan = Scans::find( $scan_id );
		if ( null === $scan || Scans::COMPLETE !== $scan['status'] ) {
			return;
		}

		$findings = Scans::findings( $scan_id );
		if ( empty( $scan['summary'] ) ) {
			$summary = $this->summary->summarize( $scan, $findings );
			Scans::update(
				$scan_id,
				array(
					'summary'        => $summary['text'],
					'summary_source' => $summary['source'],
				)
			);
			$scan = (array) Scans::find( $scan_id );
		}

		$owner = empty( $scan['owner_id'] ) ? null : Owners::find( (int) $scan['owner_id'] );
		if ( null === $owner ) {
			return;
		}

		if ( ! empty( $scan['wants_pdf'] ) && empty( $scan['pdf_sent_at'] ) ) {
			$sent = $this->mail->send_report( (string) $owner['email'], $scan, PdfRenderer::render( $scan, $findings ) );
			if ( $sent ) {
				Scans::update( $scan_id, array( 'pdf_sent_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
		}

		$this->crm->sync( $owner, $scan, $findings );
	}
}
