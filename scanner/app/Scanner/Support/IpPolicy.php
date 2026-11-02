<?php

namespace App\Scanner\Support;

/**
 * Decides whether an address is publicly routable.
 *
 * The table covers the IANA special-purpose registries rather than PHP's filter flags, which miss
 * ranges such as 100.64.0.0/10 and 192.0.0.0/24 on some builds.
 */
final class IpPolicy
{
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24',
        '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', '3fff::/20', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $packed, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $network = (string) inet_pton($network);
        if (strlen($network) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr_compare($packed, $network, 0, $bytes) !== 0) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = 0xFF & (0xFF << (8 - $remaining));

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
