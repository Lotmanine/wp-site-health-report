<?php
/**
 * Summary provider contract.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a short plain-language summary of a scan.
 */
interface SummaryProvider {

	/**
	 * Writes a summary.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return string Empty on failure.
	 */
	public function write( array $scan, array $findings ): string;
}
