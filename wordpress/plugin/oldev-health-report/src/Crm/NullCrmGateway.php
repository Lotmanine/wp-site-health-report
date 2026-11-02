<?php
/**
 * CRM stand-in for sites without FluentCRM.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Does nothing, on purpose.
 */
final class NullCrmGateway implements CrmGateway {

	/**
	 * Ignores the contact.
	 *
	 * @param array<string,mixed>            $owner    Owner row.
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return bool
	 */
	public function sync( array $owner, array $scan, array $findings ): bool {
		return false;
	}

	/**
	 * Ignores the erasure.
	 *
	 * @param string $email Address.
	 * @return void
	 */
	public function erase( string $email ): void {}
}
