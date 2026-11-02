<?php
/**
 * Scores and ranks findings.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scoring;

defined( 'ABSPATH' ) || exit;

/**
 * Category scores, overall score, grade and fix order.
 */
final class Scorer {

	private const SEVERITY_ORDER = array(
		'critical' => 0,
		'high'     => 1,
		'medium'   => 2,
		'low'      => 3,
		'none'     => 4,
	);

	/**
	 * Sorts findings into fix order and numbers them. Failures come before warnings at equal severity.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( array $findings ): array {
		usort(
			$findings,
			static function ( array $a, array $b ): int {
				return array( self::SEVERITY_ORDER[ $a['severity'] ], 'fail' === $a['outcome'] ? 0 : 1, -$a['weight'], $a['check_key'] )
					<=> array( self::SEVERITY_ORDER[ $b['severity'] ], 'fail' === $b['outcome'] ? 0 : 1, -$b['weight'], $b['check_key'] );
			}
		);
		foreach ( $findings as $index => $finding ) {
			$findings[ $index ]['rank_position'] = $index + 1;
		}
		return $findings;
	}

	/**
	 * Computes scores.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return array{overall:int,grade:string,categories:array<string,int>}
	 */
	public static function score( array $findings ): array {
		$available = array();
		$earned    = array();

		foreach ( $findings as $finding ) {
			if ( 'info' === $finding['outcome'] ) {
				continue;
			}
			$category               = (string) $finding['category'];
			$available[ $category ] = ( $available[ $category ] ?? 0 ) + (int) $finding['weight'];
			$earned[ $category ]    = ( $earned[ $category ] ?? 0 ) + self::earned( $finding );
		}

		$categories = array();
		$total      = 0.0;
		$weights    = 0;
		foreach ( Evaluator::CATEGORIES as $category => $weight ) {
			if ( empty( $available[ $category ] ) ) {
				continue;
			}
			$score                   = (int) round( 100 * $earned[ $category ] / $available[ $category ] );
			$categories[ $category ] = $score;
			$total                  += $score * $weight;
			$weights                += $weight;
		}

		$overall = 0 === $weights ? 0 : (int) round( $total / $weights );
		return array(
			'overall'    => $overall,
			'grade'      => self::grade( $overall ),
			'categories' => $categories,
		);
	}

	/**
	 * Converts a score to a letter.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	public static function grade( int $score ): string {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 70 ) {
			return 'C';
		}
		return $score >= 60 ? 'D' : 'F';
	}

	/**
	 * Returns the weight a finding earned.
	 *
	 * @param array<string,mixed> $finding Finding.
	 * @return float
	 */
	private static function earned( array $finding ): float {
		$weight = (float) $finding['weight'];
		if ( 'pass' === $finding['outcome'] ) {
			return $weight;
		}
		return 'warn' === $finding['outcome'] ? $weight / 2 : 0.0;
	}
}
