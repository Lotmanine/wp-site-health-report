<?php
/**
 * REST API routes.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Rest;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Mail\MailService;
use Oldev\HealthReport\Scan\CallbackHandler;
use Oldev\HealthReport\Scan\Maintenance;
use Oldev\HealthReport\Scan\ScanService;
use Oldev\HealthReport\Security\ClientIp;
use Oldev\HealthReport\Security\Session;
use Oldev\HealthReport\Security\Signature;
use Oldev\HealthReport\Security\Turnstile;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Public routes are gated by Turnstile, signatures or the report signature. Visitors have no WordPress
 * account, so dashboard routes use the signed session and its write token.
 */
final class Routes {

	public const NAMESPACE = 'shr/v1';

	/**
	 * Wires collaborators.
	 *
	 * @param ScanService     $scans       Scan service.
	 * @param CallbackHandler $callback    Callback handler.
	 * @param MailService     $mail        Mail service.
	 * @param Maintenance     $maintenance Maintenance service.
	 */
	public function __construct(
		private ScanService $scans,
		private CallbackHandler $callback,
		private MailService $mail,
		private Maintenance $maintenance
	) {}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register(): void {
		$public = '__return_true';
		$uuid   = '(?P<uuid>[0-9a-f-]{36})';

		register_rest_route(
			self::NAMESPACE,
			'/scans',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_scan' ),
				'permission_callback' => $public,
				'args'                => array(
					'url'               => array(
						'type'     => 'string',
						'required' => true,
					),
					'consent'           => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'turnstile_token'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'             => array( 'type' => 'string' ),
					'email_consent'     => array( 'type' => 'boolean' ),
					'marketing_consent' => array( 'type' => 'boolean' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scans/' . $uuid,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'scan_status' ),
				'permission_callback' => array( $this, 'has_report_signature' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scans/' . $uuid . '/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_scan' ),
				'permission_callback' => array( $this, 'has_report_signature' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive_callback' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/auth/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request_link' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/auth/consume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'consume_link' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => array( $this, 'can_write' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/me/rescan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rescan' ),
				'permission_callback' => array( $this, 'can_write' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/me/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_me' ),
				'permission_callback' => array( $this, 'can_write' ),
			)
		);
	}

	/**
	 * Creates a scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_scan( WP_REST_Request $request ) {
		$result = $this->scans->submit(
			array(
				'url'               => (string) $request->get_param( 'url' ),
				'consent'           => true === $request->get_param( 'consent' ),
				'turnstile_token'   => (string) $request->get_param( 'turnstile_token' ),
				'email'             => (string) $request->get_param( 'email' ),
				'email_consent'     => true === $request->get_param( 'email_consent' ),
				'marketing_consent' => true === $request->get_param( 'marketing_consent' ),
			),
			ClientIp::current()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::no_store( new WP_REST_Response( $result, 202 ) );
	}

	/**
	 * Requires a valid report signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function has_report_signature( WP_REST_Request $request ): bool {
		return Signature::verify_report_sig( (string) $request['uuid'], (string) $request->get_param( 'sig' ) );
	}

	/**
	 * Returns a scan's status for the progress screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan_status( WP_REST_Request $request ) {
		$scan = Scans::find_by_uuid( (string) $request['uuid'] );
		if ( null === $scan ) {
			return new WP_Error( 'shr_not_found', __( 'Scan not found.', 'oldev-health-report' ), array( 'status' => 404 ) );
		}
		return self::no_store( new WP_REST_Response( array( 'status' => $scan['status'] ), 200 ) );
	}

	/**
	 * Deletes one report. Holding the signed URL is the proof of access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_scan( WP_REST_Request $request ) {
		$scan = Scans::find_by_uuid( (string) $request['uuid'] );
		if ( null === $scan ) {
			return new WP_Error( 'shr_not_found', __( 'Scan not found.', 'oldev-health-report' ), array( 'status' => 404 ) );
		}
		Scans::delete( (int) $scan['id'] );
		return self::no_store( new WP_REST_Response( array( 'deleted' => true ), 200 ) );
	}

	/**
	 * Receives a signed result. The raw body is read before any JSON handling.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function receive_callback( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->callback->handle( (string) $request->get_body(), (string) $request->get_header( Signature::HEADER ) );
		return new WP_REST_Response( $result['body'], $result['status'] );
	}

	/**
	 * Sends a sign-in link. The answer is the same whether or not the address is known.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function request_link( WP_REST_Request $request ) {
		$ip      = ClientIp::current();
		$ip_hash = ClientIp::hash( $ip );
		$email   = strtolower( trim( (string) $request->get_param( 'email' ) ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'shr_invalid_email', __( 'Enter a valid email address.', 'oldev-health-report' ), array( 'status' => 422 ) );
		}
		if ( ! Counters::hit( 'link-ip|' . $ip_hash, HOUR_IN_SECONDS, Config::int( 'SHR_LIMIT_IP_HOUR', 5 ) ) ) {
			AbuseLog::record( 'link_ip_limited', $ip_hash );
			return new WP_Error( 'shr_ip_limited', __( 'Too many requests from your connection. Try again later.', 'oldev-health-report' ), array( 'status' => 429 ) );
		}
		if ( ! Turnstile::verify( (string) $request->get_param( 'turnstile_token' ), $ip ) ) {
			AbuseLog::record( 'turnstile_rejected', $ip_hash, '', 'magic_link' );
			return new WP_Error( 'shr_turnstile_failed', __( 'The security check failed. Reload the page and try again.', 'oldev-health-report' ), array( 'status' => 403 ) );
		}

		$owner = Owners::find_by_email( $email );
		if ( null !== $owner ) {
			$token = Owners::issue_link( (int) $owner['id'] );
			$this->mail->send_magic_link( $email, add_query_arg( 'magic', $token, home_url( '/dashboard/' ) ) );
		}

		return self::no_store( new WP_REST_Response( array( 'sent' => true ), 202 ) );
	}

	/**
	 * Exchanges a magic link token for a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function consume_link( WP_REST_Request $request ) {
		$owner_id = Owners::consume_link( (string) $request->get_param( 'token' ) );
		if ( 0 === $owner_id ) {
			AbuseLog::record( 'magic_link_rejected', ClientIp::hash( ClientIp::current() ) );
			return new WP_Error( 'shr_link_invalid', __( 'This link has expired or was already used.', 'oldev-health-report' ), array( 'status' => 403 ) );
		}
		Session::start( $owner_id );
		return self::no_store( new WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Requires a session and its write token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_write( WP_REST_Request $request ): bool {
		return Session::owner_id() > 0 && Session::verify_csrf( (string) $request->get_header( 'X-SHR-Token' ) );
	}

	/**
	 * Ends the session.
	 *
	 * @return WP_REST_Response
	 */
	public function logout(): WP_REST_Response {
		Session::end();
		return self::no_store( new WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Rescans a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rescan( WP_REST_Request $request ) {
		$result = $this->scans->rescan( Session::owner_id(), (string) $request->get_param( 'uuid' ), ClientIp::current() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::no_store( new WP_REST_Response( $result, 202 ) );
	}

	/**
	 * Erases the signed-in owner.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_me(): WP_REST_Response {
		$this->maintenance->erase_owner( Session::owner_id() );
		Session::end();
		return self::no_store( new WP_REST_Response( array( 'deleted' => true ), 200 ) );
	}

	/**
	 * Marks a response as uncacheable.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
