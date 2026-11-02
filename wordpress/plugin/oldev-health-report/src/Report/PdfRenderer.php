<?php
/**
 * PDF version of a report.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Report;

use Dompdf\Dompdf;
use Dompdf\Options;
use Oldev\HealthReport\Frontend\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a report with dompdf. Remote resources stay off, so scanned text cannot trigger fetches.
 */
final class PdfRenderer {

	/**
	 * Renders PDF bytes.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return string
	 */
	public static function render( array $scan, array $findings ): string {
		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setDefaultFont( 'DejaVu Sans' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( self::html( $scan, $findings ), 'UTF-8' );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();
		return (string) $dompdf->output();
	}

	/**
	 * Builds the PDF markup.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return string
	 */
	public static function html( array $scan, array $findings ): string {
		$scores = json_decode( (string) ( $scan['category_scores'] ?? '' ), true );
		$rows   = '';
		foreach ( is_array( $scores ) ? $scores : array() as $key => $score ) {
			$rows .= sprintf( '<tr><td>%s</td><td class="num">%d</td></tr>', esc_html( Labels::category( (string) $key ) ), (int) $score );
		}

		$fixes = '';
		foreach ( $findings as $finding ) {
			if ( ! in_array( $finding['outcome'], array( 'fail', 'warn' ), true ) ) {
				continue;
			}
			$fixes .= sprintf(
				'<tr><td class="num">%d</td><td>%s</td><td><strong>%s</strong><br>%s<br><span class="muted">%s</span></td></tr>',
				(int) $finding['rank_position'],
				esc_html( Labels::severity( (string) $finding['severity'] ) ),
				esc_html( (string) $finding['title'] ),
				esc_html( (string) $finding['fix'] ),
				esc_html( (string) $finding['detail'] )
			);
		}

		return sprintf(
			'<!doctype html><html><head><meta charset="utf-8"><style>
			body{font-family:"DejaVu Sans",sans-serif;font-size:11px;color:#0f172a}
			h1{font-size:20px;margin:0 0 4px}h2{font-size:14px;margin:18px 0 6px}
			table{width:100%%;border-collapse:collapse}td,th{border-bottom:1px solid #e2e8f0;padding:5px 6px;text-align:left;vertical-align:top}
			.num{width:36px}.muted{color:#64748b}.score{font-size:30px;font-weight:bold;color:#047857}
			</style></head><body>
			<h1>%s</h1><p class="muted">%s</p>
			<p class="score">%d / 100 (%s)</p><p>%s</p>
			<h2>%s</h2><table>%s</table>
			<h2>%s</h2><table>%s</table>
			<p class="muted">%s</p>
			</body></html>',
			esc_html__( 'Website health report', 'oldev-health-report' ),
			esc_html( (string) $scan['url'] . ' | ' . (string) ( $scan['completed_at'] ?? '' ) . ' UTC' ),
			(int) $scan['overall_score'],
			esc_html( (string) $scan['grade'] ),
			esc_html( (string) ( $scan['summary'] ?? '' ) ),
			esc_html__( 'Scores by category', 'oldev-health-report' ),
			$rows,
			esc_html__( 'Ranked fix list', 'oldev-health-report' ),
			'' === $fixes ? '<tr><td>' . esc_html__( 'Nothing to fix in the checks that ran.', 'oldev-health-report' ) . '</td></tr>' : $fixes,
			esc_html__( 'Passive checks only. This report is not a security audit.', 'oldev-health-report' )
		);
	}
}
