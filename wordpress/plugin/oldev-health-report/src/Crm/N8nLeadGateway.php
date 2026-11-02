<?php
/**
 * Lead storage through n8n.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Sends leads to the n8n workflow that also serves the deliverability audit. n8n keeps one row per
 * address in the Health Check tab of the leads spreadsheet and deletes it on request.
 */
final class N8nLeadGateway implements CrmGateway {

	/**
	 * Queues a lead row.
	 *
	 * @param array<string,mixed>            $owner    Owner row.
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return bool
	 */
	public function sync( array $owner, array $scan, array $findings ): bool {
		return LeadOutbox::push( 'lead.upsert', (string) $owner['email'], LeadOutbox::lead_data( $owner, $scan, $findings ) ) > 0;
	}

	/**
	 * Queues the deletion of every row for an address and drops anything still waiting to be sent.
	 *
	 * @param string $email Address.
	 * @return void
	 */
	public function erase( string $email ): void {
		LeadOutbox::forget( $email );
		LeadOutbox::push( 'lead.erase', $email, array( 'email' => strtolower( trim( $email ) ) ) );
	}
}
