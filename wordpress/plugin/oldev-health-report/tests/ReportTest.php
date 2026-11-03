<?php
/**
 * Report access, rendering and escaping.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Report\PdfRenderer;
use Oldev\HealthReport\Scan\ScanService;
use Oldev\HealthReport\Security\Signature;

class ReportTest extends TestCase {

	private function completed_scan( array $overrides = array() ): array {
		$scan = $this->make_scan();
		$this->post_callback( $this->result_message( $scan, $overrides ) );
		return (array) Scans::find( (int) $scan['id'] );
	}

	private function render( string $uuid, string $sig ): string {
		set_query_var( 'shr_scan', $uuid );
		set_query_var( 'shr_sig', $sig );
		$html = do_blocks( '<!-- wp:shr/report /-->' );
		set_query_var( 'shr_scan', '' );
		set_query_var( 'shr_sig', '' );
		return $html;
	}

	public function test_the_report_renders_for_a_valid_signature(): void {
		$scan = $this->completed_scan();

		$html = $this->render( $scan['uuid'], Signature::report_sig( $scan['uuid'] ) );

		$this->assertStringContainsString( 'site.org', $html );
		$this->assertStringContainsString( 'What to fix first', $html );
	}

	public function test_the_report_links_to_the_audit_and_contact_pages(): void {
		$scan = $this->completed_scan();

		$html = $this->render( $scan['uuid'], Signature::report_sig( $scan['uuid'] ) );

		$this->assertStringContainsString( 'href="https://oldev.site/wordpress-audit/"', $html );
		$this->assertStringContainsString( 'href="https://oldev.site/contact"', $html );
	}

	public function test_a_wrong_signature_reveals_nothing(): void {
		$scan = $this->completed_scan();

		$html = $this->render( $scan['uuid'], str_repeat( 'a', 43 ) );

		$this->assertStringContainsString( 'Report not found', $html );
		$this->assertStringNotContainsString( 'site.org', $html );
	}

	public function test_status_needs_the_signature(): void {
		$scan = $this->make_scan();

		$this->assertSame( 401, $this->rest( 'GET', '/scans/' . $scan['uuid'] )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', '/scans/' . $scan['uuid'], array( 'sig' => 'nope' ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/scans/' . $scan['uuid'], array( 'sig' => Signature::report_sig( $scan['uuid'] ) ) )->get_status() );
	}

	public function test_a_report_can_be_deleted_with_its_signature_only(): void {
		$scan = $this->completed_scan();

		$request = new \WP_REST_Request( 'POST', '/shr/v1/scans/' . $scan['uuid'] . '/delete' );
		$request->set_query_params( array( 'sig' => 'wrong' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNotNull( Scans::find( (int) $scan['id'] ) );

		$request->set_query_params( array( 'sig' => Signature::report_sig( $scan['uuid'] ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNull( Scans::find( (int) $scan['id'] ) );
		$this->assertSame( array(), Scans::findings( (int) $scan['id'] ) );
	}

	public function test_hostile_page_text_is_escaped_in_html_and_pdf(): void {
		$scanner                  = self::scanner_fixture();
		$scanner['meta']['title'] = '<script>alert(1)</script>' . str_repeat( ' long title', 8 );
		$scan                     = $this->completed_scan( array( 'scanner' => $scanner ) );

		$html = $this->render( $scan['uuid'], Signature::report_sig( $scan['uuid'] ) );
		$pdf  = PdfRenderer::html( $scan, Scans::findings( (int) $scan['id'] ) );

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert', $html );
		$this->assertStringNotContainsString( '<script>alert', $pdf );
	}

	public function test_a_pending_scan_shows_the_progress_state(): void {
		$scan = $this->make_scan();

		$html = $this->render( $scan['uuid'], Signature::report_sig( $scan['uuid'] ) );

		$this->assertStringContainsString( 'data-shr-progress', $html );
	}

	public function test_a_failed_scan_says_so(): void {
		$scan = $this->make_scan( Scans::FAILED );

		$html = $this->render( $scan['uuid'], Signature::report_sig( $scan['uuid'] ) );

		$this->assertStringContainsString( 'could not finish', $html );
	}

	public function test_the_report_url_is_unguessable(): void {
		$uuid = wp_generate_uuid4();

		$this->assertStringContainsString( '/report/' . $uuid . '/' . Signature::report_sig( $uuid ), ScanService::report_url( $uuid ) );
	}

	public function test_the_pdf_is_a_pdf(): void {
		$scan = $this->completed_scan();
		Scans::update( (int) $scan['id'], array( 'summary' => 'Summary text' ) );

		$bytes = PdfRenderer::render( (array) Scans::find( (int) $scan['id'] ), Scans::findings( (int) $scan['id'] ) );

		$this->assertStringStartsWith( '%PDF-', $bytes );
	}

	public function test_the_report_route_is_registered(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		\Oldev\HealthReport\Frontend\Blocks::add_rewrites();
		$wp_rewrite->flush_rules();

		$this->assertArrayHasKey( '^report/([0-9a-f-]{36})/([A-Za-z0-9_-]{43})/?$', (array) $wp_rewrite->wp_rewrite_rules() );
	}

	public function test_private_pages_are_not_indexed(): void {
		foreach ( array( 'report', 'dashboard' ) as $slug ) {
			$page = self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_name'  => $slug,
					'post_title' => ucfirst( $slug ),
				)
			);
			$this->go_to( get_permalink( $page ) );

			$robots = \Oldev\HealthReport\Frontend\Blocks::robots( array() );
			$this->assertTrue( $robots['noindex'], $slug );
			$this->assertTrue( $robots['nofollow'], $slug );
		}

		$this->go_to( home_url( '/' ) );
		$this->assertArrayNotHasKey( 'noindex', \Oldev\HealthReport\Frontend\Blocks::robots( array() ) );
	}
}
