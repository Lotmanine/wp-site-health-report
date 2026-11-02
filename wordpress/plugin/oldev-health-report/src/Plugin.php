<?php
/**
 * Composition root.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport;

use Oldev\HealthReport\Admin\AdminPage;
use Oldev\HealthReport\Crm\CrmGateway;
use Oldev\HealthReport\Crm\FluentCrmGateway;
use Oldev\HealthReport\Crm\NullCrmGateway;
use Oldev\HealthReport\Frontend\Blocks;
use Oldev\HealthReport\Mail\BrevoMailer;
use Oldev\HealthReport\Mail\LogMailer;
use Oldev\HealthReport\Mail\MailService;
use Oldev\HealthReport\Rest\Routes;
use Oldev\HealthReport\Scan\CallbackHandler;
use Oldev\HealthReport\Scan\Dispatcher;
use Oldev\HealthReport\Scan\Finisher;
use Oldev\HealthReport\Scan\Maintenance;
use Oldev\HealthReport\Scan\ScanService;
use Oldev\HealthReport\Summary\ClaudeSummary;
use Oldev\HealthReport\Summary\RuleSummary;
use Oldev\HealthReport\Summary\SummaryService;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services to WordPress hooks. Hook callbacks stay thin.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action(
			'init',
			static function (): void {
				Blocks::register();
				Blocks::add_rewrites();
			}
		);
		add_filter( 'query_vars', array( Blocks::class, 'query_vars' ) );
		add_action( 'template_redirect', array( Blocks::class, 'send_headers' ) );
		add_filter( 'wp_robots', array( Blocks::class, 'robots' ) );
		add_action( 'action_scheduler_init', array( Maintenance::class, 'schedule' ) );

		add_action(
			'rest_api_init',
			function (): void {
				$this->routes()->register();
			}
		);
		add_action(
			'admin_menu',
			function (): void {
				$this->admin()->register();
			}
		);
		add_action(
			'admin_post_shr_run_retention',
			function (): void {
				$this->admin()->run_retention();
			}
		);

		add_action(
			Dispatcher::ACTION,
			static function ( int $scan_id ): void {
				( new Dispatcher() )->dispatch( $scan_id );
			}
		);
		add_action(
			CallbackHandler::FINISH_ACTION,
			function ( int $scan_id ): void {
				$this->finisher()->finish( $scan_id );
			}
		);
		add_action(
			Maintenance::WATCHDOG_ACTION,
			function (): void {
				$this->maintenance()->watchdog();
			}
		);
		add_action(
			Maintenance::RETENTION_ACTION,
			function (): void {
				$this->maintenance()->retention();
			}
		);
	}

	/**
	 * Builds the mail service. An empty key selects the log-only transport.
	 *
	 * @return MailService
	 */
	public function mail(): MailService {
		$mailer = '' === Config::string( 'BREVO_API_KEY' ) ? new LogMailer() : new BrevoMailer();
		return new MailService( $mailer );
	}

	/**
	 * Builds the CRM gateway.
	 *
	 * @return CrmGateway
	 */
	public function crm(): CrmGateway {
		return function_exists( 'FluentCrmApi' ) ? new FluentCrmGateway() : new NullCrmGateway();
	}

	/**
	 * Builds the maintenance service.
	 *
	 * @return Maintenance
	 */
	public function maintenance(): Maintenance {
		return new Maintenance( $this->crm() );
	}

	/**
	 * Builds the finisher.
	 *
	 * @return Finisher
	 */
	public function finisher(): Finisher {
		return new Finisher(
			new SummaryService( new ClaudeSummary(), new RuleSummary() ),
			$this->mail(),
			$this->crm()
		);
	}

	/**
	 * Builds the REST routes.
	 *
	 * @return Routes
	 */
	private function routes(): Routes {
		return new Routes( new ScanService(), new CallbackHandler(), $this->mail(), $this->maintenance() );
	}

	/**
	 * Builds the admin page.
	 *
	 * @return AdminPage
	 */
	private function admin(): AdminPage {
		return new AdminPage( $this->maintenance() );
	}
}
