<?php
/**
 * Public scan endpoint: validation, Turnstile and limits.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Scan\Dispatcher;

class ScanRequestTest extends TestCase {

	public function test_a_valid_request_creates_a_queued_scan(): void {
		$response = $this->rest( 'POST', '/scans', $this->scan_params() );

		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertMatchesRegularExpression( '#/report/[0-9a-f-]{36}/[A-Za-z0-9_-]{43}/$#', $data['report_url'] );
		$this->assertSame( Scans::QUEUED, Scans::find_by_uuid( $data['uuid'] )['status'] );
		$this->assertTrue( as_has_scheduled_action( Dispatcher::ACTION ) );
	}

	public function test_consent_is_required(): void {
		$response = $this->rest( 'POST', '/scans', $this->scan_params( array( 'consent' => false ) ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'shr_consent_required', $response->get_data()['code'] );
	}

	public function test_turnstile_is_not_called_without_consent(): void {
		$this->rest( 'POST', '/scans', $this->scan_params( array( 'consent' => false ) ) );

		$this->assertSame( array(), $this->requests );
	}

	public function test_a_rejected_turnstile_token_stops_the_scan(): void {
		$this->turnstile( false );

		$response = $this->rest( 'POST', '/scans', $this->scan_params() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'shr_turnstile_failed', $response->get_data()['code'] );
		$this->assertSame( 'turnstile_rejected', AbuseLog::recent()[0]['event'] );
		$this->assertSame( 0, Counters::used_today( 'scans' ) );
	}

	public function test_a_turnstile_network_failure_stops_the_scan(): void {
		unset( $this->http['turnstile/v0/siteverify'] );

		$this->assertSame( 403, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
	}

	public function test_a_turnstile_server_error_stops_the_scan(): void {
		$this->http['turnstile/v0/siteverify'] = $this->http_response( 500, '{"success":true}' );

		$this->assertSame( 403, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
	}

	public function test_an_empty_turnstile_token_stops_the_scan_without_a_remote_call(): void {
		$response = $this->rest( 'POST', '/scans', $this->scan_params( array( 'turnstile_token' => '' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_missing_turnstile_secret_fails_closed(): void {
		$this->env( 'SHR_TURNSTILE_SECRET_KEY', '' );

		$this->assertSame( 403, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
	}

	public function test_turnstile_is_verified_server_side_with_the_secret(): void {
		$this->rest( 'POST', '/scans', $this->scan_params() );

		$this->assertStringContainsString( 'siteverify', $this->requests[0]['url'] );
		$this->assertSame( 'test-turnstile-secret', $this->requests[0]['args']['body']['secret'] );
		$this->assertSame( 'token', $this->requests[0]['args']['body']['response'] );
	}

	public function test_ssrf_targets_are_refused(): void {
		add_filter(
			'oldev_hr_resolve_host',
			static fn( $addresses, string $host ) => 'evil.site.org' === $host ? array( '169.254.169.254' ) : $addresses,
			20,
			2
		);

		foreach ( array( 'http://127.0.0.1/', 'http://localhost/', 'https://evil.site.org/', 'https://site.org:8080/', 'ftp://site.org/', 'https://user:pw@site.org/', 'http://2130706433/' ) as $url ) {
			$response = $this->rest( 'POST', '/scans', $this->scan_params( array( 'url' => $url ) ) );
			$this->assertSame( 422, $response->get_status(), $url );
		}
		$this->assertSame( 0, Counters::used_today( 'scans' ) );
	}

	public function test_the_hourly_ip_limit_is_enforced(): void {
		$this->env( 'SHR_LIMIT_IP_HOUR', '2' );
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );

		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
		$response = $this->rest( 'POST', '/scans', $this->scan_params() );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'shr_ip_limited', $response->get_data()['code'] );
		$this->assertSame( 'ip_limited', AbuseLog::recent()[0]['event'] );
	}

	public function test_the_daily_ip_limit_is_enforced(): void {
		$this->env( 'SHR_LIMIT_IP_HOUR', '100' );
		$this->env( 'SHR_LIMIT_IP_DAY', '1' );
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );

		$this->rest( 'POST', '/scans', $this->scan_params() );

		$this->assertSame( 429, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
	}

	public function test_limits_are_per_ip(): void {
		$this->env( 'SHR_LIMIT_IP_HOUR', '1' );
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );

		$_SERVER['REMOTE_ADDR'] = '198.51.101.1';
		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
		$_SERVER['REMOTE_ADDR'] = '198.51.101.2';
		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
	}

	public function test_a_spoofed_forwarded_header_does_not_dodge_the_limit(): void {
		$this->env( 'SHR_LIMIT_IP_HOUR', '1' );
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
		$this->rest( 'POST', '/scans', $this->scan_params() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';

		$this->assertSame( 429, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
	}

	public function test_the_domain_cooldown_is_enforced(): void {
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '60' );

		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
		$response = $this->rest( 'POST', '/scans', $this->scan_params( array( 'url' => 'https://www.site.org/' ) ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'shr_domain_cooldown', $response->get_data()['code'] );
	}

	public function test_the_global_daily_cap_is_enforced(): void {
		$this->env( 'SHR_DAILY_SCAN_CAP', '2' );

		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params() )->get_status() );
		$this->assertSame( 202, $this->rest( 'POST', '/scans', $this->scan_params( array( 'url' => 'https://other.org/' ) ) )->get_status() );
		$response = $this->rest( 'POST', '/scans', $this->scan_params( array( 'url' => 'https://third.org/' ) ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'shr_daily_cap', $response->get_data()['code'] );
		$this->assertSame( 2, Counters::used_today( 'scans' ) );
	}

	public function test_email_consent_is_recorded_only_when_ticked(): void {
		$with    = $this->rest( 'POST', '/scans', $this->scan_params( array( 'email' => 'a@example.com', 'email_consent' => true ) ) )->get_data();
		$without = $this->rest( 'POST', '/scans', $this->scan_params( array( 'url' => 'https://other.org/', 'email' => 'b@example.com' ) ) )->get_data();

		$this->assertSame( '1', (string) Scans::find_by_uuid( $with['uuid'] )['wants_pdf'] );
		$this->assertSame( '0', (string) Scans::find_by_uuid( $without['uuid'] )['wants_pdf'] );
		$this->assertNotNull( Owners::find_by_email( 'a@example.com' )['pdf_consent_at'] );
		$this->assertNull( Owners::find_by_email( 'b@example.com' )['pdf_consent_at'] );
	}

	public function test_a_later_scan_does_not_clear_consent(): void {
		$this->rest( 'POST', '/scans', $this->scan_params( array( 'email' => 'a@example.com', 'marketing_consent' => true ) ) );
		$this->rest( 'POST', '/scans', $this->scan_params( array( 'url' => 'https://other.org/', 'email' => 'a@example.com' ) ) );

		$this->assertNotNull( Owners::find_by_email( 'a@example.com' )['marketing_consent_at'] );
	}

	public function test_a_bad_email_is_refused(): void {
		$this->assertSame( 422, $this->rest( 'POST', '/scans', $this->scan_params( array( 'email' => 'nope' ) ) )->get_status() );
	}

	public function test_ip_addresses_are_stored_only_as_hashes(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.101.77';

		$data = $this->rest( 'POST', '/scans', $this->scan_params() )->get_data();
		$scan = Scans::find_by_uuid( $data['uuid'] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $scan['ip_hash'] );
		$this->assertStringNotContainsString( '198.51.101.77', (string) wp_json_encode( $scan ) );
	}
}
