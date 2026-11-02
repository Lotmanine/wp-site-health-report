<?php
/**
 * Early URL validation in WordPress. The scanner repeats these checks on every hop.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises a visitor URL and rejects anything that could reach a private network.
 */
final class UrlGuard {

	private const BLOCKED_SUFFIXES = array( 'localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'home.arpa', 'onion', 'test', 'invalid', 'example' );

	private const BLOCKED_RANGES = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.31.196.0/24',
		'192.52.193.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'192.175.48.0/24',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'::ffff:0:0/96',
		'64:ff9b::/96',
		'64:ff9b:1::/48',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'2002::/16',
		'3fff::/20',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

	/**
	 * Normalises and validates a URL.
	 *
	 * @param string $raw Visitor input.
	 * @return array{url:string,host:string,domain:string}|WP_Error
	 */
	public static function normalize( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 2000 ) {
			return new WP_Error( 'shr_invalid_url', __( 'Enter a valid website address.', 'oldev-health-report' ) );
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}

		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'shr_invalid_url', __( 'Enter a valid website address.', 'oldev-health-report' ) );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new WP_Error( 'shr_invalid_scheme', __( 'Only http and https addresses can be scanned.', 'oldev-health-report' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'shr_invalid_url', __( 'Addresses with a username or password cannot be scanned.', 'oldev-health-report' ) );
		}
		$port = (int) ( $parts['port'] ?? 0 );
		if ( 0 !== $port && 80 !== $port && 443 !== $port ) {
			return new WP_Error( 'shr_blocked_port', __( 'Only ports 80 and 443 can be scanned.', 'oldev-health-report' ) );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( ! self::is_public_hostname( $host ) ) {
			return new WP_Error( 'shr_blocked_host', __( 'This address cannot be scanned. Enter a public domain name.', 'oldev-health-report' ) );
		}

		$addresses = self::resolve( $host );
		if ( array() === $addresses ) {
			return new WP_Error( 'shr_unresolved', __( 'This domain does not resolve.', 'oldev-health-report' ) );
		}
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				return new WP_Error( 'shr_blocked_ip', __( 'This address cannot be scanned. It points to a private network.', 'oldev-health-report' ) );
			}
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$url  = $scheme . '://' . $host . ( 0 !== $port ? ':' . $port : '' ) . $path;

		return array(
			'url'    => $url,
			'host'   => $host,
			'domain' => (string) preg_replace( '/^www\./', '', $host ),
		);
	}

	/**
	 * Rejects IP literals, numeric host forms, single labels and non-public suffixes.
	 *
	 * @param string $host Lowercase host.
	 * @return bool
	 */
	public static function is_public_hostname( string $host ): bool {
		if ( strlen( $host ) > 253 || ! preg_match( '/^[a-z0-9.-]+$/', $host ) || ! str_contains( $host, '.' ) ) {
			return false;
		}
		foreach ( explode( '.', $host ) as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || str_starts_with( $label, '-' ) || str_ends_with( $label, '-' ) ) {
				return false;
			}
		}

		// Digits or hex in the last label cover 127.0.0.1, 0x7f.1 and similar inet_aton forms.
		$last = (string) substr( strrchr( '.' . $host, '.' ), 1 );
		if ( preg_match( '/^(\d+|0x[0-9a-f]+)$/', $last ) ) {
			return false;
		}

		foreach ( self::BLOCKED_SUFFIXES as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolves all A and AAAA records.
	 *
	 * @param string $host Lowercase host.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		/**
		 * Short-circuits DNS resolution, used by tests.
		 *
		 * @param string[]|null $addresses Addresses, or null to resolve normally.
		 * @param string        $host      Hostname.
		 */
		$override = apply_filters( 'oldev_hr_resolve_host', null, $host );
		if ( is_array( $override ) ) {
			return $override;
		}

		$addresses = gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : array();
		$records   = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup returns false and warns.
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Checks an address against the blocked ranges.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_public_ip( string $ip ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false and warns.
		if ( false === $packed ) {
			return false;
		}
		foreach ( self::BLOCKED_RANGES as $range ) {
			if ( self::in_range( $packed, $range ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Tests a packed address against a CIDR range of the same family.
	 *
	 * @param string $packed Packed address.
	 * @param string $range  CIDR range.
	 * @return bool
	 */
	private static function in_range( string $packed, string $range ): bool {
		list( $network, $bits ) = explode( '/', $range );
		$network                = (string) inet_pton( $network );
		if ( strlen( $network ) !== strlen( $packed ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = (int) floor( $bits / 8 );
		if ( 0 !== substr_compare( $packed, $network, 0, $bytes ) && $bytes > 0 ) {
			return false;
		}
		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}
		$mask = 0xff & ( 0xff << ( 8 - $remaining ) );
		return ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}
}
