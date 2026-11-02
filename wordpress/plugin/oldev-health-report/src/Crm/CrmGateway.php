<?php
/**
 * CRM contract.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a CRM contact in step with an owner.
 */
interface CrmGateway {

	/**
	 * Creates or updates a contact.
	 *
	 * @param array<string,mixed>            $owner    Owner row.
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return bool
	 */
	public function sync( array $owner, array $scan, array $findings ): bool;

	/**
	 * Deletes a contact.
	 *
	 * @param string $email Address.
	 * @return void
	 */
	public function erase( string $email ): void;
}
