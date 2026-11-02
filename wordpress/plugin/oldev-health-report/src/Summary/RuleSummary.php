<?php
/**
 * Rule-based summary text.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Summary;

use Oldev\HealthReport\Frontend\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic summary, used when AI is off, over its cap or failing.
 */
final class RuleSummary implements SummaryProvider {

	/**
	 * Writes a summary.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return string
	 */
	public function write( array $scan, array $findings ): string {
		$scores = json_decode( (string) ( $scan['category_scores'] ?? '' ), true );
		$scores = is_array( $scores ) ? $scores : array();
		asort( $scores );

		$parts = array(
			sprintf(
				/* translators: 1: domain, 2: score out of 100, 3: letter grade. */
				__( '%1$s scored %2$d out of 100, grade %3$s.', 'oldev-health-report' ),
				(string) $scan['domain'],
				(int) $scan['overall_score'],
				(string) $scan['grade']
			),
		);

		if ( array() !== $scores ) {
			$weakest   = (string) array_key_first( $scores );
			$strongest = (string) array_key_last( $scores );
			$parts[]   = sprintf(
				/* translators: 1: strongest category, 2: its score, 3: weakest category, 4: its score. */
				__( 'The strongest area is %1$s at %2$d and the weakest is %3$s at %4$d.', 'oldev-health-report' ),
				Labels::category( $strongest ),
				(int) $scores[ $strongest ],
				Labels::category( $weakest ),
				(int) $scores[ $weakest ]
			);
		}

		$issues = array_filter( $findings, static fn( array $finding ): bool => in_array( $finding['outcome'], array( 'fail', 'warn' ), true ) );
		if ( array() === $issues ) {
			$parts[] = __( 'No issues were found in the checks that ran.', 'oldev-health-report' );
		} else {
			$first   = reset( $issues );
			$parts[] = sprintf(
				/* translators: 1: number of issues, 2: first fix. */
				_n( 'There is %1$d item to fix. Start here: %2$s', 'There are %1$d items to fix. Start here: %2$s', count( $issues ), 'oldev-health-report' ),
				count( $issues ),
				(string) $first['fix']
			);
		}

		return implode( ' ', $parts );
	}
}
