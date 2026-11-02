<?php
/**
 * Leads and erasure through the n8n outbox.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Crm\LeadOutbox;
use Oldev\HealthReport\Crm\N8nLeadGateway;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Owners;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Scan\Maintenance;

class LeadGatewayTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->env( 'SHR_N8N_LEADS_URL', 'https://n8n.example.org/webhook/health-lead' );
		$this->env( 'SHR_HMAC_SECRET', 'test-hmac-secret' );
	}

	private function lead( string $email = 'lead@example.com' ): int {
		$owner = Owners::upsert( $email, true, false );
		$scan  = $this->make_scan( Scans::COMPLETE, (int) $owner['id'] );
		Scans::update(
			(int) $scan['id'],
			array(
				'overall_score' => 80,
				'grade'         => 'B',
			)
		);
		( new N8nLeadGateway() )->sync( $owner, (array) Scans::find( (int) $scan['id'] ), array() );
		return (int) LeadOutbox::pending()[0]['id'];
	}

	private function types(): array {
		return array_map( static fn( array $row ): string => (string) $row['type'], LeadOutbox::pending() );
	}

	public function test_a_lead_is_queued_and_scheduled_without_the_address_in_the_scheduler(): void {
		$id = $this->lead();

		$this->assertSame( array( 'lead.upsert' ), $this->types() );
		$this->assertTrue( as_has_scheduled_action( LeadOutbox::ACTION, array( $id ) ) );
		$this->assertStringNotContainsString( 'lead@example.com', (string) wp_json_encode( array( $id ) ) );
	}

	public function test_delivery_signs_the_raw_body_and_clears_the_row(): void {
		$this->http['webhook/health-lead'] = $this->http_response( 200, '{"ok":true}' );
		$id                                = $this->lead();

		LeadOutbox::deliver( $id );

		$raw  = $this->requests[0]['args']['body'];
		$body = json_decode( $raw, true );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $raw, 'test-hmac-secret' ), $this->requests[0]['args']['headers']['X-SHR-Signature'] );
		$this->assertSame( 'lead.upsert', $body['type'] );
		$this->assertSame( 1, $body['v'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $body['nonce'] );
		$this->assertLessThanOrEqual( 5, abs( time() - $body['issued_at'] ) );
		$this->assertSame( 'lead@example.com', $body['data']['email'] );
		$this->assertSame( 'B', $body['data']['grade'] );
		$this->assertSame( array(), LeadOutbox::pending() );
	}

	public function test_each_delivery_uses_a_new_nonce(): void {
		$this->http['webhook/health-lead'] = $this->http_response( 500, '' );
		$id                                = $this->lead();

		LeadOutbox::deliver( $id );
		LeadOutbox::deliver( $id );

		$first  = json_decode( $this->requests[0]['args']['body'], true );
		$second = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertNotSame( $first['nonce'], $second['nonce'] );
	}

	public function test_a_failed_delivery_is_retried_and_then_logged(): void {
		$this->http['webhook/health-lead'] = $this->http_response( 500, '' );
		$id                                = $this->lead();

		for ( $i = 0; $i < LeadOutbox::MAX_ATTEMPTS; $i++ ) {
			LeadOutbox::deliver( $id );
		}

		$this->assertSame( (string) LeadOutbox::MAX_ATTEMPTS, (string) LeadOutbox::pending()[0]['attempts'] );
		$this->assertSame( 1, (int) ( AbuseLog::counts_today()['lead_delivery_failed'] ?? 0 ) );

		$this->assertSame( 1, LeadOutbox::requeue_stalled() );
		$this->assertSame( '0', (string) LeadOutbox::pending()[0]['attempts'] );
	}

	public function test_nothing_is_sent_without_a_signing_secret(): void {
		$this->env( 'SHR_HMAC_SECRET', '' );
		$id = $this->lead();

		LeadOutbox::deliver( $id );

		$this->assertSame( array(), $this->requests );
		$this->assertCount( 1, LeadOutbox::pending() );
	}

	public function test_erasing_drops_pending_rows_for_that_address_only_and_queues_the_deletion(): void {
		$this->lead( 'gone@example.com' );
		$this->lead( 'stays@example.com' );

		( new N8nLeadGateway() )->erase( 'Gone@Example.com' );

		$rows = LeadOutbox::pending();
		$this->assertSame( array( 'lead.upsert', 'lead.erase' ), $this->types() );
		$this->assertSame( 'stays@example.com', json_decode( (string) $rows[0]['payload'], true )['email'] );
		$this->assertSame( array( 'email' => 'gone@example.com' ), json_decode( (string) $rows[1]['payload'], true ) );
	}

	public function test_delete_my_data_sends_an_erase_to_the_spreadsheet(): void {
		$this->http['webhook/health-lead'] = $this->http_response( 200, '{"ok":true}' );
		$owner                             = Owners::upsert( 'erase-me@example.com', true, false );
		$this->make_scan( Scans::COMPLETE, (int) $owner['id'] );

		( new Maintenance( new N8nLeadGateway() ) )->erase_owner( (int) $owner['id'] );
		LeadOutbox::deliver( (int) LeadOutbox::pending()[0]['id'] );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 'lead.erase', $body['type'] );
		$this->assertSame( 'erase-me@example.com', $body['data']['email'] );
		$this->assertNull( Owners::find( (int) $owner['id'] ) );
	}

	public function test_retention_erases_expired_addresses_from_the_spreadsheet_and_purges_old_rows(): void {
		global $wpdb;
		$this->lead( 'old@example.com' );
		$owner = Owners::find_by_email( 'old@example.com' );
		$past  = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', $wpdb->prefix . 'shr_scans', $past ) );
		$wpdb->update( $wpdb->prefix . 'shr_owners', array( 'created_at' => $past ), array( 'id' => $owner['id'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', $wpdb->prefix . 'shr_outbox', $past ) );

		( new Maintenance( new N8nLeadGateway() ) )->retention();

		$this->assertSame( array( 'lead.erase' ), $this->types() );
		$this->assertSame( 'old@example.com', json_decode( (string) LeadOutbox::pending()[0]['payload'], true )['email'] );
	}
}
