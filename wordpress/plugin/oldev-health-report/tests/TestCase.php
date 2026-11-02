<?php
/**
 * Shared test helpers.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Security\Session;
use Oldev\HealthReport\Security\Signature;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Environment variables to restore.
	 *
	 * @var array<string,string|false>
	 */
	private array $env = array();

	/**
	 * Canned responses by URL fragment.
	 *
	 * @var array<string,array<string,mixed>|\WP_Error>
	 */
	protected array $http = array();

	/**
	 * Requests seen by the HTTP stub.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	protected array $requests = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( array( 'scans', 'findings', 'owners', 'magic_links', 'usage', 'limits', 'nonces', 'abuse_log' ) as $table ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shr_' . $table );
		}
		$this->http     = array();
		$this->requests = array();
		Session::reset();
		remove_all_filters( 'oldev_hr_resolve_host' );
		add_filter(
			'oldev_hr_resolve_host',
			static fn( $addresses, string $host ) => array( 'site.org' => array( '8.8.8.8' ), 'www.site.org' => array( '8.8.8.8' ), 'other.org' => array( '1.1.1.1' ), 'third.org' => array( '1.0.0.1' ), 'fourth.org' => array( '9.9.9.9' ) )[ $host ] ?? array(),
			10,
			2
		);
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
		$this->turnstile( true );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		foreach ( $this->env as $name => $value ) {
			putenv( false === $value ? $name : $name . '=' . $value );
		}
		$this->env = array();
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		$_SERVER['REMOTE_ADDR'] = '203.0.114.10';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tear_down();
	}

	protected function env( string $name, string $value ): void {
		if ( ! array_key_exists( $name, $this->env ) ) {
			$this->env[ $name ] = getenv( $name );
		}
		putenv( $name . '=' . $value );
	}

	/**
	 * HTTP stub. Anything not stubbed fails, so no test can reach the network.
	 *
	 * @param mixed                $preempt Unused.
	 * @param array<string,mixed>  $args    Request args.
	 * @param string               $url     URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function stub_http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		foreach ( $this->http as $fragment => $response ) {
			if ( str_contains( $url, $fragment ) ) {
				return $response;
			}
		}
		return new \WP_Error( 'blocked', 'Unstubbed request to ' . $url );
	}

	protected function http_response( int $code, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	protected function turnstile( bool $pass ): void {
		$this->http['turnstile/v0/siteverify'] = $this->http_response( 200, (string) wp_json_encode( array( 'success' => $pass ) ) );
	}

	protected function rest( string $method, string $route, array $params = array(), array $headers = array(), ?string $raw = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/shr/v1' . $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( null !== $raw ) {
			$request->set_body( $raw );
		} elseif ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	protected function scan_params( array $overrides = array() ): array {
		return array_merge(
			array(
				'url'             => 'https://site.org/',
				'consent'         => true,
				'turnstile_token' => 'token',
			),
			$overrides
		);
	}

	protected function make_scan( string $status = Scans::DISPATCHED, ?int $owner_id = null, string $domain = 'site.org' ): array {
		$scan = Scans::create( 'https://' . $domain . '/', $domain, 'hash', $owner_id, false );
		Scans::update( (int) $scan['id'], array( 'status' => $status ) );
		return (array) Scans::find( (int) $scan['id'] );
	}

	protected function result_message( array $scan, array $overrides = array() ): array {
		return array_merge(
			array(
				'v'         => 1,
				'type'      => 'scan.result',
				'scan_id'   => $scan['uuid'],
				'status'    => 'complete',
				'error'     => null,
				'issued_at' => time(),
				'nonce'     => bin2hex( random_bytes( 16 ) ),
				'scanner'   => self::scanner_fixture(),
				'pagespeed' => array(
					'score'  => 91,
					'lcp_ms' => 1800,
					'cls'    => 0.02,
					'tbt_ms' => 40,
					'error'  => null,
				),
				'dns'       => array(
					'spf'   => array(
						'found'  => true,
						'record' => 'v=spf1 include:mail.site.org -all',
						'error'  => null,
					),
					'dmarc' => array(
						'found'  => true,
						'record' => 'v=DMARC1; p=reject',
						'error'  => null,
					),
					'dkim'  => array(
						'found'     => true,
						'selectors' => array( 'selector1' ),
						'checked'   => 12,
						'error'     => null,
					),
				),
			),
			$overrides
		);
	}

	protected static function scanner_fixture(): array {
		return array(
			'final_url' => 'https://site.org/',
			'https'     => true,
			'tls'       => array(
				'valid'          => true,
				'days_remaining' => 60,
				'issuer'         => 'Test CA',
				'error'          => null,
			),
			'headers'   => array(
				'strict-transport-security' => 'max-age=31536000',
				'x-content-type-options'    => 'nosniff',
			),
			'wordpress' => array(
				'detected' => true,
				'version'  => '7.1.4',
			),
			'robots'    => array(
				'found'        => true,
				'disallow_all' => false,
			),
			'sitemap'   => array(
				'found' => true,
				'url'   => 'https://site.org/sitemap.xml',
			),
			'meta'      => array(
				'title'       => 'A perfectly normal title',
				'description' => 'A meta description that is long enough to be within the recommended limits.',
				'canonical'   => 'https://site.org/',
				'robots'      => '',
			),
		);
	}

	protected function post_callback( array $message ): WP_REST_Response {
		$body = (string) wp_json_encode( $message );
		return $this->rest( 'POST', '/callback', array(), array( Signature::HEADER => Signature::sign( $body ) ), $body );
	}
}
