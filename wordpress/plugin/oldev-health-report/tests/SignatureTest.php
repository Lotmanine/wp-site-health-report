<?php
/**
 * Signature primitives.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Security\Signature;

class SignatureTest extends TestCase {

	public function test_a_signed_body_verifies(): void {
		$this->assertTrue( Signature::verify( '{"a":1}', Signature::sign( '{"a":1}' ) ) );
	}

	public function test_the_signature_matches_a_plain_hmac_of_the_raw_bytes(): void {
		$this->env( 'SHR_HMAC_SECRET', 'known' );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', 'body', 'known' ), Signature::sign( 'body' ) );
	}

	public function test_a_changed_body_fails(): void {
		$this->assertFalse( Signature::verify( '{"a":2}', Signature::sign( '{"a":1}' ) ) );
	}

	public function test_a_wrong_secret_fails(): void {
		$signature = Signature::sign( 'body' );
		$this->env( 'SHR_HMAC_SECRET', 'another-secret' );
		$this->assertFalse( Signature::verify( 'body', $signature ) );
	}

	public function test_an_empty_secret_fails_closed(): void {
		$this->env( 'SHR_HMAC_SECRET', '' );
		$this->assertFalse( Signature::verify( 'body', '' ) );
		$this->assertFalse( Signature::verify( 'body', 'sha256=' . hash_hmac( 'sha256', 'body', '' ) ) );
	}

	public function test_freshness_window(): void {
		$this->assertTrue( Signature::is_fresh( time() - 299 ) );
		$this->assertFalse( Signature::is_fresh( time() - 301 ) );
		$this->assertFalse( Signature::is_fresh( time() + 301 ) );
	}

	public function test_verification_uses_a_constant_time_comparison(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Security/Signature.php' );
		$this->assertSame( 2, substr_count( $source, 'hash_equals(' ) );
	}

	public function test_report_signature_is_url_safe_and_bound_to_the_uuid(): void {
		$sig = Signature::report_sig( 'uuid-one' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $sig );
		$this->assertTrue( Signature::verify_report_sig( 'uuid-one', $sig ) );
		$this->assertFalse( Signature::verify_report_sig( 'uuid-two', $sig ) );
	}
}
