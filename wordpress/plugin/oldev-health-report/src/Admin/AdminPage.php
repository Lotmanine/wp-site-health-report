<?php
/**
 * Operations screen.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Admin;

use ActionScheduler_Store;
use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Scan\Maintenance;
use Oldev\HealthReport\Scan\ScanService;

defined( 'ABSPATH' ) || exit;

/**
 * Queue health, daily usage and the abuse log.
 */
final class AdminPage {

	public const SLUG = 'shr-operations';

	/**
	 * Wires collaborators.
	 *
	 * @param Maintenance $maintenance Maintenance service.
	 */
	public function __construct( private Maintenance $maintenance ) {}

	/**
	 * Adds the menu item.
	 *
	 * @return void
	 */
	public function register(): void {
		add_menu_page(
			__( 'Health Report', 'oldev-health-report' ),
			__( 'Health Report', 'oldev-health-report' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-heart',
			58
		);
	}

	/**
	 * Runs the retention job on demand.
	 *
	 * @return void
	 */
	public function run_retention(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oldev-health-report' ), 403 );
		}
		check_admin_referer( 'shr_run_retention' );
		$this->maintenance->retention();
		wp_safe_redirect( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'oldev-health-report' ), 403 );
		}

		$queue   = self::queue_counts();
		$usage   = array(
			array( __( 'Scans', 'oldev-health-report' ), Counters::used_today( 'scans' ), Config::int( 'SHR_DAILY_SCAN_CAP', 100 ) ),
			array( __( 'Emails', 'oldev-health-report' ), Counters::used_today( 'emails' ), Config::daily_email_cap() ),
			array( __( 'AI calls', 'oldev-health-report' ), Counters::used_today( 'ai' ), Config::int( 'SHR_DAILY_AI_CAP', 25 ) ),
		);
		$outcome = Scans::status_counts_today();
		$events  = AbuseLog::recent( 50 );
		$counts  = AbuseLog::counts_today();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Health Report operations', 'oldev-health-report' ); ?></h1>

			<h2><?php esc_html_e( 'Queue health', 'oldev-health-report' ); ?></h2>
			<table class="widefat striped" style="max-width:520px">
				<tbody>
				<?php foreach ( $queue as $label => $value ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( (string) $value ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Daily usage', 'oldev-health-report' ); ?></h2>
			<table class="widefat striped" style="max-width:520px">
				<thead><tr>
					<th><?php esc_html_e( 'Metric', 'oldev-health-report' ); ?></th>
					<th><?php esc_html_e( 'Used', 'oldev-health-report' ); ?></th>
					<th><?php esc_html_e( 'Cap', 'oldev-health-report' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $usage as $row ) : ?>
					<tr><td><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( (string) $row[1] ); ?></td><td><?php echo esc_html( (string) $row[2] ); ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( $outcome as $status => $total ) : ?>
					<?php /* translators: %s: scan status. */ ?>
					<tr><td><?php echo esc_html( sprintf( __( 'Scans today: %s', 'oldev-health-report' ), $status ) ); ?></td><td><?php echo esc_html( (string) $total ); ?></td><td></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Abuse log', 'oldev-health-report' ); ?></h2>
			<p>
				<?php foreach ( $counts as $event => $total ) : ?>
					<code><?php echo esc_html( $event . ': ' . $total ); ?></code>
				<?php endforeach; ?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Time (UTC)', 'oldev-health-report' ); ?></th>
					<th><?php esc_html_e( 'Event', 'oldev-health-report' ); ?></th>
					<th><?php esc_html_e( 'Domain', 'oldev-health-report' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'oldev-health-report' ); ?></th>
					<th><?php esc_html_e( 'Client hash', 'oldev-health-report' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( array() === $events ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No events.', 'oldev-health-report' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $event['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $event['event'] ); ?></td>
						<td><?php echo esc_html( (string) $event['domain'] ); ?></td>
						<td><?php echo esc_html( (string) $event['detail'] ); ?></td>
						<td><code><?php echo esc_html( substr( (string) $event['ip_hash'], 0, 12 ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="shr_run_retention">
				<?php wp_nonce_field( 'shr_run_retention' ); ?>
				<?php submit_button( __( 'Run retention now', 'oldev-health-report' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Reads queue state from Action Scheduler.
	 *
	 * @return array<string,int|string>
	 */
	private static function queue_counts(): array {
		$pending = as_get_scheduled_actions(
			array(
				'group'    => ScanService::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			),
			'ids'
		);

		$store = ActionScheduler_Store::instance();
		$count = static fn( string $status ): int => (int) $store->query_actions(
			array(
				'group'  => ScanService::GROUP,
				'status' => $status,
			),
			'count'
		);

		$oldest = __( 'none', 'oldev-health-report' );
		if ( array() !== $pending ) {
			$date = $store->get_date( (string) reset( $pending ) );
			if ( $date ) {
				$oldest = $date->format( 'Y-m-d H:i:s' ) . ' UTC';
			}
		}

		return array(
			__( 'Pending', 'oldev-health-report' )      => $count( ActionScheduler_Store::STATUS_PENDING ),
			__( 'Running', 'oldev-health-report' )      => $count( ActionScheduler_Store::STATUS_RUNNING ),
			__( 'Failed', 'oldev-health-report' )       => $count( ActionScheduler_Store::STATUS_FAILED ),
			__( 'Completed', 'oldev-health-report' )    => $count( ActionScheduler_Store::STATUS_COMPLETE ),
			__( 'Next pending', 'oldev-health-report' ) => $oldest,
		);
	}
}
