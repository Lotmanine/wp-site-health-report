<?php
/**
 * Early URL checks in WordPress.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Tests;

use Oldev\HealthReport\Security\UrlGuard;

class UrlGuardTest extends TestCase {

	public function test_a_bare_domain_gets_https(): void {
		$result = UrlGuard::normalize( 'Site.org' );

		$this->assertSame( 'https://site.org/', $result['url'] );
		$this->assertSame( 'site.org', $result['domain'] );
	}

	public function test_www_is_dropped_from_the_domain(): void {
		$this->assertSame( 'site.org', UrlGuard::normalize( 'https://www.site.org/' )['domain'] );
	}

	/**
	 * @dataProvider blocked_ips
	 */
	public function test_private_and_reserved_addresses_are_blocked( string $ip ): void {
		$this->assertFalse( UrlGuard::is_public_ip( $ip ) );
	}

	public static function blocked_ips(): array {
		return array_map(
			static fn( string $ip ): array => array( $ip ),
			array( '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '240.0.0.1', '::1', '::', '::ffff:10.0.0.1', '64:ff9b::1', 'fd00::1', 'fe80::1', '2001:db8::1', 'ff02::1', 'not-an-ip' )
		);
	}

	public function test_public_addresses_pass(): void {
		$this->assertTrue( UrlGuard::is_public_ip( '8.8.8.8' ) );
		$this->assertTrue( UrlGuard::is_public_ip( '2606:4700:4700::1111' ) );
	}

	/**
	 * @dataProvider blocked_hosts
	 */
	public function test_numeric_and_local_hosts_are_blocked( string $host ): void {
		$this->assertFalse( UrlGuard::is_public_hostname( $host ) );
	}

	public static function blocked_hosts(): array {
		return array_map(
			static fn( string $host ): array => array( $host ),
			array( 'localhost', '127.0.0.1', '2130706433', '0x7f.1', '0x7f.0x0.0x0.0x1', 'printer.local', 'db.internal', 'wordpress', 'a..b', '-bad.site.org' )
		);
	}

	public function test_a_mixed_dns_answer_is_refused(): void {
		add_filter( 'oldev_hr_resolve_host', static fn(): array => array( '8.8.8.8', '10.0.0.1' ), 20 );

		$this->assertSame( 'shr_blocked_ip', UrlGuard::normalize( 'https://site.org/' )->get_error_code() );
	}

	public function test_an_unresolved_host_is_refused(): void {
		$this->assertSame( 'shr_unresolved', UrlGuard::normalize( 'https://missing.org/' )->get_error_code() );
	}

	public function test_the_client_ip_ignores_forwarded_headers_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.101.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame( '198.51.101.5', \Oldev\HealthReport\Security\ClientIp::current() );
	}

	public function test_the_client_ip_reads_from_the_trusted_end_of_the_chain(): void {
		$this->env( 'SHR_TRUSTED_PROXY_HOPS', '1' );
		$_SERVER['REMOTE_ADDR']          = '172.18.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.101.5';

		$this->assertSame( '198.51.101.5', \Oldev\HealthReport\Security\ClientIp::current() );
	}
}
