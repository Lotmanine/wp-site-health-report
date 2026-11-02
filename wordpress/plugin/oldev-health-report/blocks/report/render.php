<?php
/**
 * Report page markup.
 *
 * @package OldevHealthReport
 */

use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Frontend\Labels;
use Oldev\HealthReport\Security\Signature;

defined( 'ABSPATH' ) || exit;

$shr_uuid = (string) get_query_var( 'shr_scan' );
$shr_sig  = (string) get_query_var( 'shr_sig' );
$shr_scan = Signature::verify_report_sig( $shr_uuid, $shr_sig ) ? Scans::find_by_uuid( $shr_uuid ) : null;
$shr_attr = get_block_wrapper_attributes( array( 'class' => 'shr-report' ) );

if ( null === $shr_scan ) : ?>
	<div <?php echo $shr_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?>>
		<h2><?php esc_html_e( 'Report not found', 'oldev-health-report' ); ?></h2>
		<p><?php esc_html_e( 'This link is not valid, or the report was deleted. Reports are kept for 30 days.', 'oldev-health-report' ); ?></p>
		<p><a class="shr-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Run a new scan', 'oldev-health-report' ); ?></a></p>
	</div>
	<?php
	return;
endif;

$shr_status = (string) $shr_scan['status'];
$shr_rest   = add_query_arg( 'sig', rawurlencode( $shr_sig ), rest_url( 'shr/v1/scans/' . $shr_uuid ) );
$shr_delete = add_query_arg( 'sig', rawurlencode( $shr_sig ), rest_url( 'shr/v1/scans/' . $shr_uuid . '/delete' ) );

if ( in_array( $shr_status, array( Scans::QUEUED, Scans::DISPATCHED ), true ) ) :
	?>
	<div <?php echo $shr_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?> data-shr-progress data-status-url="<?php echo esc_url( $shr_rest ); ?>">
		<h2><?php esc_html_e( 'Checking your website', 'oldev-health-report' ); ?></h2>
		<p><?php echo esc_html( (string) $shr_scan['domain'] ); ?></p>
		<div class="shr-progress" role="progressbar" aria-label="<?php esc_attr_e( 'Scan in progress', 'oldev-health-report' ); ?>"><span></span></div>
		<ol class="shr-steps">
			<li><?php esc_html_e( 'Reading the home page, headers and certificate', 'oldev-health-report' ); ?></li>
			<li><?php esc_html_e( 'Measuring performance with PageSpeed Insights', 'oldev-health-report' ); ?></li>
			<li><?php esc_html_e( 'Looking up SPF, DKIM and DMARC', 'oldev-health-report' ); ?></li>
			<li><?php esc_html_e( 'Scoring the results', 'oldev-health-report' ); ?></li>
		</ol>
		<p class="shr-note" role="status"><?php esc_html_e( 'This usually takes about a minute. This page updates by itself.', 'oldev-health-report' ); ?></p>
	</div>
	<?php
	return;
endif;

if ( Scans::FAILED === $shr_status ) :
	?>
	<div <?php echo $shr_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?>>
		<h2><?php esc_html_e( 'The scan could not finish', 'oldev-health-report' ); ?></h2>
		<p><?php esc_html_e( 'The site may have refused the connection, or a service was unavailable. Nothing was charged to your scan history.', 'oldev-health-report' ); ?></p>
		<p><a class="shr-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Try again later', 'oldev-health-report' ); ?></a></p>
	</div>
	<?php
	return;
endif;

$shr_findings = Scans::findings( (int) $shr_scan['id'] );
$shr_scores   = json_decode( (string) $shr_scan['category_scores'], true );
$shr_scores   = is_array( $shr_scores ) ? $shr_scores : array();
$shr_fixes    = array_filter( $shr_findings, static fn( array $finding ): bool => in_array( $finding['outcome'], array( 'fail', 'warn' ), true ) );
$shr_others   = array_filter( $shr_findings, static fn( array $finding ): bool => in_array( $finding['outcome'], array( 'pass', 'info' ), true ) );
?>
<div <?php echo $shr_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?>>
	<header class="shr-hero">
		<div class="shr-score grade-<?php echo esc_attr( strtolower( (string) $shr_scan['grade'] ) ); ?>">
			<strong data-testid="overall-score"><?php echo esc_html( (string) $shr_scan['overall_score'] ); ?></strong>
			<span><?php esc_html_e( 'out of 100', 'oldev-health-report' ); ?></span>
		</div>
		<div>
			<p class="shr-eyebrow"><?php esc_html_e( 'Website health report', 'oldev-health-report' ); ?></p>
			<h2><?php echo esc_html( (string) $shr_scan['domain'] ); ?></h2>
			<?php /* translators: %s: letter grade. */ ?>
			<p class="shr-grade"><?php echo esc_html( sprintf( __( 'Grade %s', 'oldev-health-report' ), (string) $shr_scan['grade'] ) ); ?></p>
			<?php if ( ! empty( $shr_scan['summary'] ) ) : ?>
				<p data-testid="summary"><?php echo esc_html( (string) $shr_scan['summary'] ); ?></p>
			<?php else : ?>
				<p data-shr-progress data-status-url="" class="shr-note"><?php esc_html_e( 'The summary is being written.', 'oldev-health-report' ); ?></p>
			<?php endif; ?>
		</div>
	</header>

	<section aria-labelledby="shr-categories">
		<h3 id="shr-categories"><?php esc_html_e( 'Scores by category', 'oldev-health-report' ); ?></h3>
		<div class="shr-cards">
			<?php foreach ( $shr_scores as $shr_key => $shr_value ) : ?>
				<div class="shr-card">
					<span><?php echo esc_html( Labels::category( (string) $shr_key ) ); ?></span>
					<strong><?php echo esc_html( (string) $shr_value ); ?></strong>
					<div class="shr-meter"><i style="width:<?php echo esc_attr( (string) (int) $shr_value ); ?>%"></i></div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section aria-labelledby="shr-fixes">
		<h3 id="shr-fixes"><?php esc_html_e( 'What to fix first', 'oldev-health-report' ); ?></h3>
		<?php if ( array() === $shr_fixes ) : ?>
			<p><?php esc_html_e( 'Nothing to fix in the checks that ran.', 'oldev-health-report' ); ?></p>
		<?php else : ?>
			<ol class="shr-fixes" data-testid="fix-list">
				<?php foreach ( $shr_fixes as $shr_finding ) : ?>
					<li>
						<span class="shr-badge is-<?php echo esc_attr( (string) $shr_finding['severity'] ); ?>"><?php echo esc_html( Labels::severity( (string) $shr_finding['severity'] ) ); ?></span>
						<strong><?php echo esc_html( (string) $shr_finding['title'] ); ?></strong>
						<p><?php echo esc_html( (string) $shr_finding['fix'] ); ?></p>
						<?php if ( '' !== (string) $shr_finding['detail'] ) : ?>
							<p class="shr-detail"><?php echo esc_html( (string) $shr_finding['detail'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</section>

	<section class="shr-cta" aria-labelledby="shr-cta">
		<h3 id="shr-cta"><?php esc_html_e( 'Want a person to look deeper?', 'oldev-health-report' ); ?></h3>
		<p><?php esc_html_e( 'This report only covers what can be seen from outside. A full audit reviews the site from the inside.', 'oldev-health-report' ); ?></p>
		<p class="shr-cta-actions">
			<a class="shr-button" href="https://oldev.site/wordpress-audit/"><?php esc_html_e( 'See the WordPress audit', 'oldev-health-report' ); ?></a>
			<a class="shr-button is-ghost" href="https://oldev.site/contact"><?php esc_html_e( 'Contact OLDEV', 'oldev-health-report' ); ?></a>
		</p>
	</section>

	<section aria-labelledby="shr-passed">
		<h3 id="shr-passed"><?php esc_html_e( 'Other checks', 'oldev-health-report' ); ?></h3>
		<ul class="shr-passed">
			<?php foreach ( $shr_others as $shr_finding ) : ?>
				<li>
					<span><?php echo esc_html( (string) $shr_finding['title'] ); ?></span>
					<em><?php echo esc_html( Labels::outcome( (string) $shr_finding['outcome'] ) ); ?></em>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<footer class="shr-report-foot">
		<?php /* translators: %s: date and time. */ ?>
		<p class="shr-note"><?php echo esc_html( sprintf( __( 'Scanned %s UTC. Passive checks only. This report is not a security audit.', 'oldev-health-report' ), (string) $shr_scan['completed_at'] ) ); ?></p>
		<button class="shr-link" type="button" data-shr-delete="<?php echo esc_url( $shr_delete ); ?>"><?php esc_html_e( 'Delete this report', 'oldev-health-report' ); ?></button>
	</footer>
</div>
