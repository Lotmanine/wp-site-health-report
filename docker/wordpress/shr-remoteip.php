<?php
/**
 * Writes the mod_remoteip trust list for Apache before it starts.
 *
 * The WordPress image trusts every private range as a proxy. Here only the networks this container is
 * attached to are trusted, which is where the Coolify proxy (Traefik) connects from. Apache then takes the
 * visitor address from X-Forwarded-For only when the connection comes from one of those networks, and
 * WordPress and Wordfence read it from REMOTE_ADDR.
 *
 * Usage: php shr-remoteip.php /etc/apache2/conf-available/remoteip.conf
 */

$target = $argv[1] ?? '';
$routes = @file( '/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$cidrs  = array();

foreach ( array_slice( (array) $routes, 1 ) as $line ) {
	$f = preg_split( '/\s+/', trim( $line ) );
	// Directly connected networks have no gateway. The default route has destination 0.
	if ( count( $f ) < 8 || '00000000' !== $f[2] || '00000000' === $f[1] ) {
		continue;
	}
	// /proc/net/route stores addresses as little-endian hex.
	$network = long2ip( unpack( 'V', pack( 'H*', $f[1] ) )[1] );
	$mask    = unpack( 'V', pack( 'H*', $f[7] ) )[1];
	$bits    = substr_count( decbin( $mask ), '1' );
	if ( $bits >= 8 ) {
		$cidrs[ "$network/$bits" ] = true;
	}
}

if ( '' === $target || ! $cidrs ) {
	fwrite( STDERR, "shr-remoteip: no attached network found, keeping the image default\n" );
	exit( 0 );
}

$conf = "# Written at container start by shr-remoteip.php. Only the attached networks are trusted proxies.\n"
	. "RemoteIPHeader X-Forwarded-For\n";
foreach ( array_keys( $cidrs ) as $cidr ) {
	$conf .= "RemoteIPInternalProxy $cidr\n";
}
file_put_contents( $target, $conf );
fwrite( STDOUT, 'shr-remoteip: trusted ' . implode( ', ', array_keys( $cidrs ) ) . "\n" );
