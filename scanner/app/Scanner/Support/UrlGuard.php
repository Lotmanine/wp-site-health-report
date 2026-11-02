<?php

namespace App\Scanner\Support;

/**
 * The SSRF boundary. Every request the scanner makes goes through check() first.
 */
final class UrlGuard
{
    private const BLOCKED_SUFFIXES = [
        'localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'home.arpa', 'onion',
        'test', 'invalid', 'example',
    ];

    public function __construct(private Resolver $resolver) {}

    /**
     * @throws BlockedTarget
     */
    public function check(string $url): Target
    {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new BlockedTarget('invalid_url');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new BlockedTarget('invalid_url');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new BlockedTarget('blocked_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedTarget('credentials_in_url');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port !== 80 && $port !== 443) {
            throw new BlockedTarget('blocked_port');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (! $this->isPublicHostname($host)) {
            throw new BlockedTarget('blocked_host');
        }

        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw new BlockedTarget('unresolved_host');
        }

        // One private record is enough: a mixed answer is a known way to slip past a first-record check.
        foreach ($addresses as $address) {
            if (! IpPolicy::isPublic($address)) {
                throw new BlockedTarget('blocked_ip');
            }
        }

        usort($addresses, fn (string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));

        return new Target($url, $scheme, $host, $port, $addresses[0]);
    }

    private function isPublicHostname(string $host): bool
    {
        if (strlen($host) > 253 || ! preg_match('/^[a-z0-9.-]+$/', $host) || ! str_contains($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }

        // A numeric last label covers 127.0.0.1, 2130706433, 0x7f.1 and other inet_aton spellings.
        $last = substr((string) strrchr('.'.$host, '.'), 1);
        if (preg_match('/^(\d+|0x[0-9a-f]+)$/', $last)) {
            return false;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }
}
