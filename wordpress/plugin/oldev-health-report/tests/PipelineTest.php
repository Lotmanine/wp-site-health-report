<?php
/**
 * Dispatch, follow-up work, retention, scoring and the admin screen.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Admin\AdminPage;
use Oldev\HealthReport\Crm\CrmGateway;
use Oldev\HealthReport\Crm\FluentCrmGateway;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Mail\Mailer;
use Oldev\HealthReport\Mail\MailService;
use Oldev\HealthReport\Scan\Dispatcher;
use Oldev\HealthReport\Scan\Finisher;
use Oldev\HealthReport\Scan\Maintenance;
use Oldev\HealthReport\Scoring\Evaluator;
use Oldev\HealthReport\Scoring\Scorer;
use Oldev\HealthReport\Security\Signature;
use Oldev\HealthReport\Summary\ClaudeSummary;
use Oldev\HealthReport\Summary\RuleSummary;
use Oldev\HealthReport\Summary\SummaryService;

class SpyMailer implements Mailer {

	public array $sent = array();

	public function send( string $to, string $subject, string $html, ?array $attachment = null ): bool {
		$this->sent[] = compact( 'to', 'subject', 'attachment' );
		return true;
	}
}

class SpyCrm implements CrmGateway {

	public array $synced = array();

	public array $erased = array();

	public function sync( array $owner, array $scan, array $findings ): bool {
		$this->synced[] = $owner['email'];
		return true;
	}

	public function erase( string $email ): void {
		$this->erased[] = $email;
	}
}

class PipelineTest extends TestCase {

	private function finisher( SpyMailer $mailer, SpyCrm $crm ): Finisher {
		return new Finisher( new SummaryService( new ClaudeSummary(), new RuleSummary() ), new MailService( $mailer ), $crm );
	}

	private function finished_scan( bool $wants_pdf, ?int $owner_id ): array {
		$scan = Scans::create( 'https://site.org/', 'site.org', 'hash', $owner_id, $wants_pdf );
		Scans::update( (int) $scan['id'], array( 'status' => Scans::DISPATCHED ) );
		$this->post_callback( $this->result_message( $scan ) );
		return (array) Scans::find( (int) $scan['id'] );
	}

	public function test_the_job_is_signed_over_the_exact_body(): void {
		$this->http['n8n.test.invalid'] = $this->http_response( 202, '{}' );
		$scan                           = Scans::create( 'https://site.org/', 'site.org', 'hash', null, false );

		( new Dispatcher() )->dispatch( (int) $scan['id'] );

		$args = $this->requests[0]['args'];
		$body = json_decode( $args['body'], true );
		$this->assertSame( Signature::sign( $args['body'] ), $args['headers'][ Signature::HEADER ] );
		$this->assertSame( 'scan.job', $body['type'] );
		$this->assertSame( $scan['uuid'], $body['scan_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $body['nonce'] );
		$this->assertSame( Scans::DISPATCHED, Scans::find( (int) $scan['id'] )['status'] );
	}

	public function test_a_failed_delivery_is_retried_then_marked_failed(): void {
		$this->http['n8n.test.invalid'] = $this->http_response( 500, '{}' );
		$scan                           = Scans::create( 'https://site.org/', 'site.org', 'hash', null, false );
		$dispatcher                     = new Dispatcher();

		$dispatcher->dispatch( (int) $scan['id'] );
		$this->assertSame( Scans::QUEUED, Scans::find( (int) $scan['id'] )['status'] );
		$this->assertTrue( as_has_scheduled_action( Dispatcher::ACTION, array( (int) $scan['id'] ) ) );

		$dispatcher->dispatch( (int) $scan['id'] );
		$dispatcher->dispatch( (int) $scan['id'] );

		$stored = Scans::find( (int) $scan['id'] );
		$this->assertSame( Scans::FAILED, $stored['status'] );
		$this->assertSame( 'dispatch_failed', $stored['failure_reason'] );
		$this->assertCount( 3, $this->requests );
	}

	public function test_a_missing_webhook_url_never_sends(): void {
		$this->env( 'SHR_N8N_WEBHOOK_URL', '' );
		$scan = Scans::create( 'https://site.org/', 'site.org', 'hash', null, false );

		( new Dispatcher() )->dispatch( (int) $scan['id'] );

		$this->assertSame( array(), $this->requests );
	}

	public function test_the_pdf_is_sent_only_with_consent(): void {
		$owner  = Owners::upsert( 'a@example.com', true, false );
		$scan   = $this->finished_scan( true, (int) $owner['id'] );
		$mailer = new SpyMailer();
		$crm    = new SpyCrm();

		$this->finisher( $mailer, $crm )->finish( (int) $scan['id'] );

		$this->assertCount( 1, $mailer->sent );
		$this->assertSame( 'a@example.com', $mailer->sent[0]['to'] );
		$this->assertStringStartsWith( '%PDF-', $mailer->sent[0]['attachment']['content'] );
		$this->assertNotNull( Scans::find( (int) $scan['id'] )['pdf_sent_at'] );
		$this->assertSame( array( 'a@example.com' ), $crm->synced );
	}

	public function test_no_pdf_without_consent(): void {
		$owner  = Owners::upsert( 'a@example.com', false, false );
		$scan   = $this->finished_scan( false, (int) $owner['id'] );
		$mailer = new SpyMailer();

		$this->finisher( $mailer, new SpyCrm() )->finish( (int) $scan['id'] );

		$this->assertSame( array(), $mailer->sent );
	}

	public function test_no_email_means_no_crm_contact(): void {
		$scan = $this->finished_scan( false, null );
		$crm  = new SpyCrm();

		$this->finisher( new SpyMailer(), $crm )->finish( (int) $scan['id'] );

		$this->assertSame( array(), $crm->synced );
		$this->assertNotEmpty( Scans::find( (int) $scan['id'] )['summary'] );
	}

	public function test_a_rerun_does_not_send_the_pdf_twice(): void {
		$owner  = Owners::upsert( 'a@example.com', true, false );
		$scan   = $this->finished_scan( true, (int) $owner['id'] );
		$mailer = new SpyMailer();

		$this->finisher( $mailer, new SpyCrm() )->finish( (int) $scan['id'] );
		$this->finisher( $mailer, new SpyCrm() )->finish( (int) $scan['id'] );

		$this->assertCount( 1, $mailer->sent );
	}

	public function test_crm_tags_follow_findings_and_consent(): void {
		$tags = FluentCrmGateway::tags_for(
			array(
				'pdf_consent_at'       => '2026-11-02 10:00:00',
				'marketing_consent_at' => null,
			),
			array( 'grade' => 'C' ),
			array(
				array(
					'category' => 'security',
					'outcome'  => 'fail',
				),
				array(
					'category' => 'wordpress',
					'outcome'  => 'pass',
				),
				array(
					'category' => 'email',
					'outcome'  => 'warn',
				),
			)
		);

		$this->assertArrayHasKey( 'shr-grade-c', $tags );
		$this->assertArrayHasKey( 'shr-consent-pdf', $tags );
		$this->assertArrayHasKey( 'shr-no-marketing', $tags );
		$this->assertArrayHasKey( 'shr-fail-security', $tags );
		$this->assertArrayHasKey( 'shr-wordpress', $tags );
		$this->assertArrayNotHasKey( 'shr-fail-email', $tags );
		$this->assertArrayNotHasKey( 'shr-consent-marketing', $tags );
	}

	public function test_retention_deletes_what_is_past_thirty_days_and_keeps_the_rest(): void {
		$old_owner = Owners::upsert( 'old@example.com', true, false );
		$new_owner = Owners::upsert( 'new@example.com', true, false );
		$old       = $this->make_scan( Scans::COMPLETE, (int) $old_owner['id'] );
		$new       = $this->make_scan( Scans::COMPLETE, (int) $new_owner['id'] );

		global $wpdb;
		$past = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
		Scans::update( (int) $old['id'], array( 'created_at' => $past ) );
		$wpdb->update( $wpdb->prefix . 'shr_owners', array( 'created_at' => $past ), array( 'id' => $old_owner['id'] ) );
		$wpdb->insert(
			$wpdb->prefix . 'shr_abuse_log',
			array(
				'event'      => 'old_event',
				'created_at' => $past,
			)
		);
		$crm = new SpyCrm();

		$deleted = ( new Maintenance( $crm ) )->retention();

		$this->assertSame( 1, $deleted );
		$this->assertNull( Scans::find( (int) $old['id'] ) );
		$this->assertNotNull( Scans::find( (int) $new['id'] ) );
		$this->assertNull( Owners::find( (int) $old_owner['id'] ) );
		$this->assertNotNull( Owners::find( (int) $new_owner['id'] ) );
		$this->assertSame( array( 'old@example.com' ), $crm->erased );
		$this->assertSame( array(), AbuseLog::recent() );
	}

	public function test_a_scan_just_inside_thirty_days_is_kept(): void {
		$scan = $this->make_scan( Scans::COMPLETE );
		Scans::update( (int) $scan['id'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 29 * DAY_IN_SECONDS ) ) );

		( new Maintenance( new SpyCrm() ) )->retention();

		$this->assertNotNull( Scans::find( (int) $scan['id'] ) );
	}

	public function test_the_watchdog_fails_stuck_scans_only(): void {
		$stuck = $this->make_scan();
		$fresh = $this->make_scan();
		Scans::update( (int) $stuck['id'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ) );

		$this->assertSame( 1, ( new Maintenance( new SpyCrm() ) )->watchdog() );

		$this->assertSame( 'timed_out', Scans::find( (int) $stuck['id'] )['failure_reason'] );
		$this->assertSame( Scans::DISPATCHED, Scans::find( (int) $fresh['id'] )['status'] );
	}

	public function test_scoring_counts_pass_warn_and_fail(): void {
		$findings = array(
			array(
				'category' => 'seo',
				'outcome'  => 'pass',
				'weight'   => 4,
			),
			array(
				'category' => 'seo',
				'outcome'  => 'warn',
				'weight'   => 4,
			),
			array(
				'category' => 'seo',
				'outcome'  => 'fail',
				'weight'   => 4,
			),
		);

		$scores = Scorer::score( $findings );

		$this->assertSame( 50, $scores['categories']['seo'] );
		$this->assertSame( 50, $scores['overall'] );
		$this->assertSame( 'F', $scores['grade'] );
	}

	public function test_unavailable_checks_leave_the_score_alone(): void {
		$scan = array( 'scanner' => self::scanner_fixture() );

		$without = Scorer::score( Evaluator::evaluate( $scan ) );

		$this->assertArrayNotHasKey( 'performance', $without['categories'] );
		$this->assertArrayNotHasKey( 'email', $without['categories'] );
	}

	public function test_a_pagespeed_error_is_not_scored_as_zero(): void {
		$message              = $this->result_message( array( 'uuid' => 'x' ) );
		$healthy              = Scorer::score( Evaluator::evaluate( $message ) );
		$message['pagespeed'] = array( 'error' => 'quota' );
		$limited              = Scorer::score( Evaluator::evaluate( $message ) );

		$this->assertArrayHasKey( 'performance', $healthy['categories'] );
		$this->assertArrayNotHasKey( 'performance', $limited['categories'] );
		$this->assertGreaterThan( 0, $limited['overall'] );
	}

	public function test_a_site_without_wordpress_has_no_wordpress_category(): void {
		$message                         = $this->result_message( array( 'uuid' => 'x' ) );
		$message['scanner']['wordpress'] = array( 'detected' => false );

		$this->assertArrayNotHasKey( 'wordpress', Scorer::score( Evaluator::evaluate( $message ) )['categories'] );
	}

	public function test_the_fix_list_ranks_critical_failures_first(): void {
		$message                             = $this->result_message( array( 'uuid' => 'x' ) );
		$message['scanner']['tls']['valid']  = false;
		$message['scanner']['meta']['title'] = '';

		$ranked = Scorer::rank( Evaluator::evaluate( $message ) );

		$this->assertSame( 'tls_valid', $ranked[0]['check_key'] );
		$this->assertSame( 1, $ranked[0]['rank_position'] );
	}

	public function test_grades(): void {
		$this->assertSame( 'A', Scorer::grade( 90 ) );
		$this->assertSame( 'B', Scorer::grade( 89 ) );
		$this->assertSame( 'C', Scorer::grade( 70 ) );
		$this->assertSame( 'D', Scorer::grade( 60 ) );
		$this->assertSame( 'F', Scorer::grade( 59 ) );
	}

	public function test_noindex_and_weak_spf_are_caught(): void {
		$message                              = $this->result_message( array( 'uuid' => 'x' ) );
		$message['scanner']['meta']['robots'] = 'noindex';
		$message['dns']['spf']['record']      = 'v=spf1 +all';

		$by_key = array_column( Evaluator::evaluate( $message ), 'outcome', 'check_key' );

		$this->assertSame( 'fail', $by_key['indexable'] );
		$this->assertSame( 'fail', $by_key['spf'] );
	}

	public function test_a_subscriber_cannot_open_the_admin_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		( new AdminPage( new Maintenance( new SpyCrm() ) ) )->render();
	}

	public function test_a_subscriber_cannot_run_retention(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		( new AdminPage( new Maintenance( new SpyCrm() ) ) )->run_retention();
	}

	public function test_retention_from_the_admin_screen_needs_a_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->expectException( \WPDieException::class );
		( new AdminPage( new Maintenance( new SpyCrm() ) ) )->run_retention();
	}

	public function test_an_administrator_sees_queue_usage_and_abuse(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		AbuseLog::record( 'bad_signature', '', 'site.org', 'callback' );

		ob_start();
		( new AdminPage( new Maintenance( new SpyCrm() ) ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Queue health', $html );
		$this->assertStringContainsString( 'Daily usage', $html );
		$this->assertStringContainsString( 'bad_signature', $html );
	}
}
