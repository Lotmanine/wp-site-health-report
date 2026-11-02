<?php
/**
 * Magic links, sessions, rescans and deletion.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Security\Session;

class DashboardTest extends TestCase {

	private function mail_dir(): string {
		$dir = sys_get_temp_dir() . '/shr-mail-test-' . getmypid();
		$this->env( 'SHR_MAIL_LOG_DIR', $dir );
		$this->env( 'SHR_N8N_LEADS_URL', '' );
		return $dir;
	}

	private function owner( string $email = 'owner@example.com' ): array {
		return Owners::upsert( $email, true, false );
	}

	private function sign_in( int $owner_id ): array {
		Session::start( $owner_id );
		return array( 'X-SHR-Token' => Session::csrf_token() );
	}

	public function test_a_magic_link_works_once(): void {
		$owner = $this->owner();
		$token = Owners::issue_link( (int) $owner['id'] );

		$this->assertSame( 200, $this->rest( 'POST', '/auth/consume', array( 'token' => $token ) )->get_status() );
		$this->assertSame( (int) $owner['id'], Session::owner_id() );

		Session::reset();
		$this->assertSame( 403, $this->rest( 'POST', '/auth/consume', array( 'token' => $token ) )->get_status() );
		$this->assertSame( 0, Session::owner_id() );
	}

	public function test_an_expired_link_is_refused(): void {
		global $wpdb;
		$token = Owners::issue_link( (int) $this->owner()['id'] );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s', $wpdb->prefix . 'shr_magic_links', '2000-01-01 00:00:00' ) );

		$this->assertSame( 403, $this->rest( 'POST', '/auth/consume', array( 'token' => $token ) )->get_status() );
	}

	public function test_only_the_token_hash_is_stored(): void {
		global $wpdb;
		$token = Owners::issue_link( (int) $this->owner()['id'] );

		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT token_hash FROM %i', $wpdb->prefix . 'shr_magic_links' ) );

		$this->assertNotSame( $token, $stored );
		$this->assertSame( hash( 'sha256', $token ), $stored );
	}

	public function test_the_request_answers_the_same_for_known_and_unknown_addresses(): void {
		$dir = $this->mail_dir();
		$this->owner();

		$known   = $this->rest( 'POST', '/auth/request', array( 'email' => 'owner@example.com', 'turnstile_token' => 't' ) );
		$unknown = $this->rest( 'POST', '/auth/request', array( 'email' => 'nobody@example.com', 'turnstile_token' => 't' ) );

		$this->assertSame( $known->get_status(), $unknown->get_status() );
		$this->assertSame( $known->get_data(), $unknown->get_data() );

		$messages = glob( $dir . '/*.json' );
		$this->assertCount( 1, $messages );
		array_map( 'unlink', (array) glob( $dir . '/*' ) );
		rmdir( $dir );
	}

	public function test_the_magic_link_request_needs_turnstile(): void {
		$this->turnstile( false );

		$response = $this->rest( 'POST', '/auth/request', array( 'email' => 'owner@example.com', 'turnstile_token' => 't' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_forged_session_cookie_is_ignored(): void {
		$owner = $this->owner();

		$_COOKIE[ Session::COOKIE ] = $owner['id'] . '.' . ( time() + 3600 ) . '.' . str_repeat( 'a', 64 );

		$this->assertSame( 0, Session::owner_id() );
	}

	public function test_an_expired_session_is_ignored(): void {
		$owner = $this->owner();
		Session::start( (int) $owner['id'] );
		$cookie = $_COOKIE[ Session::COOKIE ];
		Session::reset();

		list( $id, , $mac )         = explode( '.', $cookie );
		$_COOKIE[ Session::COOKIE ] = $id . '.' . ( time() - 10 ) . '.' . $mac;

		$this->assertSame( 0, Session::owner_id() );
	}

	public function test_writes_need_the_session_token(): void {
		$owner = $this->owner();
		$scan  = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		Session::start( (int) $owner['id'] );

		$response = $this->rest( 'POST', '/me/rescan', array( 'uuid' => $scan['uuid'] ), array( 'X-SHR-Token' => 'wrong' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_writes_need_a_session(): void {
		$this->assertSame( 401, $this->rest( 'POST', '/me/delete', array(), array( 'X-SHR-Token' => 'x' ) )->get_status() );
	}

	public function test_an_owner_can_rescan_their_own_site(): void {
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );
		$owner   = $this->owner();
		$scan    = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		$headers = $this->sign_in( (int) $owner['id'] );

		$response = $this->rest( 'POST', '/me/rescan', array( 'uuid' => $scan['uuid'] ), $headers );

		$this->assertSame( 202, $response->get_status() );
		$this->assertCount( 2, Scans::for_owner( (int) $owner['id'] ) );
	}

	public function test_an_owner_cannot_rescan_someone_elses_scan(): void {
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );
		$first   = $this->owner( 'first@example.com' );
		$second  = $this->owner( 'second@example.com' );
		$scan    = $this->make_scan( Scans::COMPLETE, (int) $first['id'] );
		$headers = $this->sign_in( (int) $second['id'] );

		$this->assertSame( 404, $this->rest( 'POST', '/me/rescan', array( 'uuid' => $scan['uuid'] ), $headers )->get_status() );
	}

	public function test_a_rescan_respects_the_domain_cooldown(): void {
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '60' );
		$owner   = $this->owner();
		$scan    = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		$headers = $this->sign_in( (int) $owner['id'] );

		$this->assertSame( 202, $this->rest( 'POST', '/me/rescan', array( 'uuid' => $scan['uuid'] ), $headers )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', '/me/rescan', array( 'uuid' => $scan['uuid'] ), $headers )->get_status() );
	}

	public function test_a_rescan_respects_the_daily_cap(): void {
		$this->env( 'SHR_DAILY_SCAN_CAP', '0' );
		$this->env( 'SHR_DOMAIN_COOLDOWN_MINUTES', '0' );
		$owner   = $this->owner();
		$scan    = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		$headers = $this->sign_in( (int) $owner['id'] );

		$this->assertSame( 429, $this->rest( 'POST', '/me/rescan', array( 'uuid' => $scan['uuid'] ), $headers )->get_status() );
	}

	public function test_delete_my_data_removes_everything(): void {
		$owner = $this->owner();
		$scan  = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		Scans::save_findings(
			(int) $scan['id'],
			array(
				array(
					'check_key'     => 'x',
					'category'      => 'seo',
					'outcome'       => 'fail',
					'severity'      => 'low',
					'weight'        => 1,
					'rank_position' => 1,
					'title'         => 't',
					'detail'        => '',
					'fix'           => '',
				),
			)
		);
		Owners::issue_link( (int) $owner['id'] );
		$headers = $this->sign_in( (int) $owner['id'] );

		$response = $this->rest( 'POST', '/me/delete', array(), $headers );

		global $wpdb;
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Owners::find( (int) $owner['id'] ) );
		$this->assertNull( Scans::find( (int) $scan['id'] ) );
		$this->assertSame( array(), Scans::findings( (int) $scan['id'] ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'shr_magic_links' ) ) );
		$this->assertSame( 0, Session::owner_id() );
	}

	public function test_the_dashboard_shows_only_the_owners_scans_and_compares_them(): void {
		$owner  = $this->owner();
		$other  = $this->owner( 'other@example.com' );
		$before = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		$after  = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		$this->make_scan( Scans::COMPLETE, (int) $other['id'], 'other.org' );
		Scans::update(
			(int) $before['id'],
			array(
				'overall_score'   => 60,
				'grade'           => 'D',
				'category_scores' => '{"security":40}',
			)
		);
		Scans::update(
			(int) $after['id'],
			array(
				'overall_score'   => 85,
				'grade'           => 'B',
				'category_scores' => '{"security":80}',
			)
		);
		Session::start( (int) $owner['id'] );
		$_GET['before'] = $before['uuid'];
		$_GET['after']  = $after['uuid'];

		$html = do_blocks( '<!-- wp:shr/dashboard /-->' );
		unset( $_GET['before'], $_GET['after'] );

		$this->assertStringContainsString( 'Before and after', $html );
		$this->assertStringContainsString( '+25', $html );
		$this->assertStringContainsString( '+40', $html );
		$this->assertStringNotContainsString( 'other.org', $html );
	}

	public function test_comparing_someone_elses_scan_shows_nothing(): void {
		$owner = $this->owner();
		$other = $this->owner( 'other@example.com' );
		$mine  = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		$their = $this->make_scan( Scans::COMPLETE, (int) $other['id'], 'other.org' );
		Session::start( (int) $owner['id'] );
		$_GET['before'] = $their['uuid'];
		$_GET['after']  = $mine['uuid'];

		$html = do_blocks( '<!-- wp:shr/dashboard /-->' );
		unset( $_GET['before'], $_GET['after'] );

		$this->assertStringNotContainsString( 'Before and after', $html );
	}

	public function test_a_magic_link_page_shows_a_button_and_does_not_consume_the_token(): void {
		$owner         = $this->owner();
		$token         = Owners::issue_link( (int) $owner['id'] );
		$_GET['magic'] = $token;

		$html = do_blocks( '<!-- wp:shr/dashboard /-->' );
		unset( $_GET['magic'] );

		$this->assertStringContainsString( 'data-shr-consume', $html );
		$this->assertSame( 200, $this->rest( 'POST', '/auth/consume', array( 'token' => $token ) )->get_status() );
	}
}
