<?php
/**
 * Signed callback handling.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Scans;
use Oldev\HealthReport\Security\Signature;

class CallbackTest extends TestCase {

	public function test_a_valid_result_is_stored_and_scored(): void {
		$scan = $this->make_scan();

		$response = $this->post_callback( $this->result_message( $scan ) );

		$this->assertSame( 200, $response->get_status() );
		$stored = Scans::find( (int) $scan['id'] );
		$this->assertSame( Scans::COMPLETE, $stored['status'] );
		$this->assertNotNull( $stored['overall_score'] );
		$this->assertNotEmpty( Scans::findings( (int) $scan['id'] ) );
	}

	public function test_a_bad_signature_is_rejected_and_logged(): void {
		$scan = $this->make_scan();
		$body = (string) wp_json_encode( $this->result_message( $scan ) );

		$response = $this->rest( 'POST', '/callback', array(), array( Signature::HEADER => 'sha256=' . str_repeat( '0', 64 ) ), $body );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( Scans::DISPATCHED, Scans::find( (int) $scan['id'] )['status'] );
		$this->assertSame( 'bad_signature', AbuseLog::recent()[0]['event'] );
	}

	public function test_a_missing_signature_is_rejected(): void {
		$scan = $this->make_scan();

		$response = $this->rest( 'POST', '/callback', array(), array(), (string) wp_json_encode( $this->result_message( $scan ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_tampered_body_is_rejected(): void {
		$scan      = $this->make_scan();
		$body      = (string) wp_json_encode( $this->result_message( $scan ) );
		$signature = Signature::sign( $body );

		$response = $this->rest( 'POST', '/callback', array(), array( Signature::HEADER => $signature ), str_replace( '"score":91', '"score":99', $body ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_replayed_callback_is_rejected(): void {
		$scan    = $this->make_scan();
		$message = $this->result_message( $scan );

		$first  = $this->post_callback( $message );
		$second = $this->post_callback( $message );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'replayed', $second->get_data()['code'] );
		$this->assertSame( 'replayed_callback', AbuseLog::recent()[0]['event'] );
	}

	public function test_a_replayed_nonce_on_another_scan_is_rejected(): void {
		$first  = $this->make_scan();
		$second = $this->make_scan( Scans::DISPATCHED, null, 'other.org' );
		$nonce  = bin2hex( random_bytes( 16 ) );

		$this->post_callback( $this->result_message( $first, array( 'nonce' => $nonce ) ) );
		$response = $this->post_callback( $this->result_message( $second, array( 'nonce' => $nonce ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( Scans::DISPATCHED, Scans::find( (int) $second['id'] )['status'] );
	}

	public function test_a_stale_callback_is_rejected(): void {
		$scan = $this->make_scan();

		$response = $this->post_callback( $this->result_message( $scan, array( 'issued_at' => time() - 301 ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'stale', $response->get_data()['code'] );
	}

	public function test_a_message_from_the_future_is_rejected(): void {
		$scan = $this->make_scan();

		$response = $this->post_callback( $this->result_message( $scan, array( 'issued_at' => time() + 301 ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_finished_scan_cannot_be_overwritten(): void {
		$scan = $this->make_scan();
		$this->post_callback( $this->result_message( $scan ) );
		$score = Scans::find( (int) $scan['id'] )['overall_score'];

		$response = $this->post_callback( $this->result_message( $scan, array( 'status' => 'failed', 'error' => 'late' ) ) );

		$this->assertSame( 409, $response->get_status() );
		$stored = Scans::find( (int) $scan['id'] );
		$this->assertSame( Scans::COMPLETE, $stored['status'] );
		$this->assertSame( $score, $stored['overall_score'] );
	}

	public function test_a_failure_message_marks_the_scan_failed(): void {
		$scan = $this->make_scan();

		$response = $this->post_callback( $this->result_message( $scan, array( 'status' => 'failed', 'error' => 'scanner_failed' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$stored = Scans::find( (int) $scan['id'] );
		$this->assertSame( Scans::FAILED, $stored['status'] );
		$this->assertSame( 'scanner_failed', $stored['failure_reason'] );
	}

	public function test_an_unknown_scan_is_rejected(): void {
		$message = $this->result_message( array( 'uuid' => wp_generate_uuid4() ) );

		$this->assertSame( 404, $this->post_callback( $message )->get_status() );
	}

	public function test_a_wrong_message_type_is_rejected(): void {
		$scan = $this->make_scan();

		$this->assertSame( 400, $this->post_callback( $this->result_message( $scan, array( 'type' => 'scan.job' ) ) )->get_status() );
	}

	public function test_an_oversized_body_is_rejected_before_any_work(): void {
		$body = str_repeat( 'a', 1048577 );

		$response = $this->rest( 'POST', '/callback', array(), array( Signature::HEADER => Signature::sign( $body ) ), $body );

		$this->assertSame( 413, $response->get_status() );
	}
}
