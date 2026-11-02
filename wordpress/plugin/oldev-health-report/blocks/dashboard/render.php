<?php
/**
 * Dashboard markup.
 *
 * @package OldevHealthReport
 */

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Frontend\Labels;
use Oldev\HealthReport\Scan\ScanService;
use Oldev\HealthReport\Security\Session;

defined( 'ABSPATH' ) || exit;

$shr_attr  = get_block_wrapper_attributes( array( 'class' => 'shr-dash' ) );
$shr_owner = Session::owner_id() > 0 ? Owners::find( Session::owner_id() ) : null;

if ( null === $shr_owner ) :
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display; the token is checked by the REST route.
	$shr_magic = isset( $_GET['magic'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_GET['magic'] ) ) ) : '';
	?>
	<div <?php echo $shr_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?>
		data-shr-dashboard
		data-consume="<?php echo esc_url( rest_url( 'shr/v1/auth/consume' ) ); ?>"
		data-request="<?php echo esc_url( rest_url( 'shr/v1/auth/request' ) ); ?>">
		<?php if ( '' !== $shr_magic ) : ?>
			<?php /* A button, so that mail scanners following the link cannot spend it. */ ?>
			<h2><?php esc_html_e( 'Open your dashboard', 'oldev-health-report' ); ?></h2>
			<button class="shr-button" type="button" data-shr-consume="<?php echo esc_attr( $shr_magic ); ?>"><?php esc_html_e( 'Continue', 'oldev-health-report' ); ?></button>
			<p class="shr-error" role="alert" hidden></p>
		<?php else : ?>
			<h2><?php esc_html_e( 'Your scan history', 'oldev-health-report' ); ?></h2>
			<p><?php esc_html_e( 'Enter the email address you used on the scan form. If it has reports, a one-time sign-in link will be sent.', 'oldev-health-report' ); ?></p>
			<form class="shr-form" data-shr-request novalidate>
				<label class="shr-label" for="shr-dash-email"><?php esc_html_e( 'Email address', 'oldev-health-report' ); ?></label>
				<div class="shr-row">
					<input class="shr-input" id="shr-dash-email" name="email" type="email" autocomplete="email" required>
					<button class="shr-button" type="submit"><?php esc_html_e( 'Send sign-in link', 'oldev-health-report' ); ?></button>
				</div>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Config::string( 'SHR_TURNSTILE_SITE_KEY' ) ); ?>" data-size="flexible"></div>
				<p class="shr-error" role="alert" hidden></p>
				<p class="shr-note" role="status" data-shr-sent hidden><?php esc_html_e( 'If that address has reports, a link is on its way.', 'oldev-health-report' ); ?></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;

$shr_scans = Scans::for_owner( (int) $shr_owner['id'] );
$shr_done  = array_values( array_filter( $shr_scans, static fn( array $scan ): bool => Scans::COMPLETE === $scan['status'] ) );

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only comparison, scoped to the signed-in owner below.
$shr_before_id = isset( $_GET['before'] ) ? sanitize_text_field( wp_unslash( $_GET['before'] ) ) : '';
$shr_after_id  = isset( $_GET['after'] ) ? sanitize_text_field( wp_unslash( $_GET['after'] ) ) : '';
// phpcs:enable

$shr_by_uuid = array_column( $shr_done, null, 'uuid' );
$shr_before  = $shr_by_uuid[ $shr_before_id ] ?? null;
$shr_after   = $shr_by_uuid[ $shr_after_id ] ?? null;
?>
<div <?php echo $shr_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?>
	data-shr-dashboard
	data-token="<?php echo esc_attr( Session::csrf_token() ); ?>"
	data-rescan="<?php echo esc_url( rest_url( 'shr/v1/me/rescan' ) ); ?>"
	data-delete="<?php echo esc_url( rest_url( 'shr/v1/me/delete' ) ); ?>"
	data-logout="<?php echo esc_url( rest_url( 'shr/v1/auth/logout' ) ); ?>">
	<h2><?php esc_html_e( 'Your scan history', 'oldev-health-report' ); ?></h2>
	<?php /* translators: %s: email address. */ ?>
	<p class="shr-note"><?php echo esc_html( sprintf( __( 'Signed in as %s', 'oldev-health-report' ), (string) $shr_owner['email'] ) ); ?></p>
	<p class="shr-error" role="alert" hidden></p>

	<?php if ( null !== $shr_before && null !== $shr_after ) : ?>
		<?php
		$shr_b_scores = (array) json_decode( (string) $shr_before['category_scores'], true );
		$shr_a_scores = (array) json_decode( (string) $shr_after['category_scores'], true );
		$shr_keys     = array_unique( array_merge( array_keys( $shr_b_scores ), array_keys( $shr_a_scores ) ) );
		$shr_delta    = static fn( int $change ): string => 0 === $change ? '0' : sprintf( '%+d', $change );
		$shr_problem  = static function ( int $scan_id ): array {
			$issues = array_filter( Scans::findings( $scan_id ), static fn( array $finding ): bool => in_array( $finding['outcome'], array( 'fail', 'warn' ), true ) );
			return array_column( $issues, 'title', 'check_key' );
		};
		$shr_b_issues = $shr_problem( (int) $shr_before['id'] );
		$shr_a_issues = $shr_problem( (int) $shr_after['id'] );
		$shr_fixed    = array_diff_key( $shr_b_issues, $shr_a_issues );
		$shr_new      = array_diff_key( $shr_a_issues, $shr_b_issues );
	?>
		<section aria-labelledby="shr-compare" data-testid="comparison">
			<h3 id="shr-compare"><?php esc_html_e( 'Before and after', 'oldev-health-report' ); ?></h3>
			<table class="shr-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Category', 'oldev-health-report' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Before', 'oldev-health-report' ); ?></th>
					<th scope="col"><?php esc_html_e( 'After', 'oldev-health-report' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'oldev-health-report' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Overall', 'oldev-health-report' ); ?></th>
						<td><?php echo esc_html( (string) $shr_before['overall_score'] ); ?></td>
						<td><?php echo esc_html( (string) $shr_after['overall_score'] ); ?></td>
						<td><?php echo esc_html( $shr_delta( (int) $shr_after['overall_score'] - (int) $shr_before['overall_score'] ) ); ?></td>
					</tr>
					<?php foreach ( $shr_keys as $shr_key ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( Labels::category( (string) $shr_key ) ); ?></th>
							<td><?php echo esc_html( (string) ( $shr_b_scores[ $shr_key ] ?? '-' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $shr_a_scores[ $shr_key ] ?? '-' ) ); ?></td>
							<td>
								<?php
								if ( isset( $shr_b_scores[ $shr_key ], $shr_a_scores[ $shr_key ] ) ) {
									echo esc_html( $shr_delta( (int) $shr_a_scores[ $shr_key ] - (int) $shr_b_scores[ $shr_key ] ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( array() !== $shr_fixed ) : ?>
				<h4><?php esc_html_e( 'Resolved', 'oldev-health-report' ); ?></h4>
				<ul><?php foreach ( $shr_fixed as $shr_title ) : ?>
					<li><?php echo esc_html( (string) $shr_title ); ?></li>
				<?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( array() !== $shr_new ) : ?>
				<h4><?php esc_html_e( 'New since the earlier scan', 'oldev-health-report' ); ?></h4>
				<ul><?php foreach ( $shr_new as $shr_title ) : ?>
					<li><?php echo esc_html( (string) $shr_title ); ?></li>
				<?php endforeach; ?></ul>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( array() === $shr_scans ) : ?>
		<p><?php esc_html_e( 'No scans yet.', 'oldev-health-report' ); ?></p>
	<?php else : ?>
		<table class="shr-table" data-testid="history">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Site', 'oldev-health-report' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'oldev-health-report' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Score', 'oldev-health-report' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'oldev-health-report' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $shr_scans as $shr_row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( ScanService::report_url( (string) $shr_row['uuid'] ) ); ?>"><?php echo esc_html( (string) $shr_row['domain'] ); ?></a></td>
					<td><?php echo esc_html( substr( (string) $shr_row['created_at'], 0, 16 ) ); ?></td>
					<td><?php echo esc_html( Scans::COMPLETE === $shr_row['status'] ? (string) $shr_row['overall_score'] . ' (' . (string) $shr_row['grade'] . ')' : (string) $shr_row['status'] ); ?></td>
					<td><button class="shr-link" type="button" data-shr-rescan="<?php echo esc_attr( (string) $shr_row['uuid'] ); ?>"><?php esc_html_e( 'Rescan', 'oldev-health-report' ); ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( count( $shr_done ) >= 2 ) : ?>
		<form class="shr-compare-form" method="get" action="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>">
			<label><?php esc_html_e( 'Before', 'oldev-health-report' ); ?>
				<select name="before" class="shr-input">
					<?php foreach ( $shr_done as $shr_index => $shr_row ) : ?>
						<option value="<?php echo esc_attr( (string) $shr_row['uuid'] ); ?>" <?php selected( 1 === $shr_index ); ?>><?php echo esc_html( $shr_row['domain'] . ' ' . substr( (string) $shr_row['created_at'], 0, 16 ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'After', 'oldev-health-report' ); ?>
				<select name="after" class="shr-input">
					<?php foreach ( $shr_done as $shr_index => $shr_row ) : ?>
						<option value="<?php echo esc_attr( (string) $shr_row['uuid'] ); ?>" <?php selected( 0 === $shr_index ); ?>><?php echo esc_html( $shr_row['domain'] . ' ' . substr( (string) $shr_row['created_at'], 0, 16 ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button class="shr-button is-ghost" type="submit"><?php esc_html_e( 'Compare', 'oldev-health-report' ); ?></button>
		</form>
	<?php endif; ?>

	<div class="shr-dash-foot">
		<button class="shr-link" type="button" data-shr-logout><?php esc_html_e( 'Sign out', 'oldev-health-report' ); ?></button>
		<button class="shr-link is-danger" type="button" data-shr-erase><?php esc_html_e( 'Delete my data', 'oldev-health-report' ); ?></button>
	</div>
</div>
