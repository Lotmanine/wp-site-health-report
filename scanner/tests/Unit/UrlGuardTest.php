<?php

namespace Tests\Unit;

use App\Scanner\Support\BlockedTarget;
use App\Scanner\Support\IpPolicy;
use App\Scanner\Support\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeResolver;

class UrlGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.1.2.3'],
            'private 172' => ['172.16.0.1'],
            'private 192' => ['192.168.1.1'],
            'link local and cloud metadata' => ['169.254.169.254'],
            'shared address space' => ['100.64.0.1'],
            'zero network' => ['0.0.0.0'],
            'benchmark' => ['198.18.0.1'],
            'documentation' => ['203.0.113.5'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv4 mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4 mapped private' => ['::ffff:10.0.0.1'],
            'nat64' => ['64:ff9b::7f00:1'],
            '6to4' => ['2002:7f00:1::'],
            'unique local' => ['fd00::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 documentation' => ['2001:db8::1'],
            'ipv6 multicast' => ['ff02::1'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_private_and_reserved_addresses_are_not_public(string $ip): void
    {
        $this->assertFalse(IpPolicy::isPublic($ip));
    }

    public function test_public_addresses_are_allowed(): void
    {
        $this->assertTrue(IpPolicy::isPublic('8.8.8.8'));
        $this->assertTrue(IpPolicy::isPublic('76.13.63.48'));
        $this->assertTrue(IpPolicy::isPublic('2606:4700:4700::1111'));
    }

    #[DataProvider('blockedAddresses')]
    public function test_a_host_resolving_to_a_blocked_address_is_rejected(string $ip): void
    {
        $guard = new UrlGuard(new FakeResolver(['evil.site.org' => [$ip]]));

        $this->assertBlocked($guard, 'https://evil.site.org/', 'blocked_ip');
    }

    public function test_one_private_record_among_public_ones_rejects_the_host(): void
    {
        $guard = new UrlGuard(new FakeResolver(['mixed.site.org' => ['8.8.8.8', '10.0.0.1']]));

        $this->assertBlocked($guard, 'https://mixed.site.org/', 'blocked_ip');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd', 'invalid_url'],
            'gopher scheme' => ['gopher://site.org/', 'blocked_scheme'],
            'ftp scheme' => ['ftp://site.org/', 'blocked_scheme'],
            'port 22' => ['https://site.org:22/', 'blocked_port'],
            'port 8080' => ['http://site.org:8080/', 'blocked_port'],
            'port 6379' => ['http://site.org:6379/', 'blocked_port'],
            'embedded credentials' => ['https://user:pass@site.org/', 'credentials_in_url'],
            'localhost' => ['http://localhost/', 'blocked_host'],
            'ipv4 literal' => ['http://127.0.0.1/', 'blocked_host'],
            'ipv4 public literal' => ['http://8.8.8.8/', 'blocked_host'],
            'decimal host' => ['http://2130706433/', 'blocked_host'],
            'hex host' => ['http://0x7f.0x0.0x0.0x1/', 'blocked_host'],
            'short hex host' => ['http://0x7f.1/', 'blocked_host'],
            'ipv6 literal' => ['http://[::1]/', 'blocked_host'],
            'local suffix' => ['http://printer.local/', 'blocked_host'],
            'internal suffix' => ['http://db.internal/', 'blocked_host'],
            'single label' => ['http://wordpress/', 'blocked_host'],
            'no such host' => ['https://missing.site.org/', 'unresolved_host'],
            'whitespace' => ["https://site.org/\n", 'invalid_url'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_unsafe_urls_are_rejected(string $url, string $reason): void
    {
        $guard = new UrlGuard(new FakeResolver(['site.org' => ['8.8.8.8']]));

        $this->assertBlocked($guard, $url, $reason);
    }

    public function test_a_public_host_passes_and_prefers_ipv4(): void
    {
        $guard = new UrlGuard(new FakeResolver(['site.org' => ['2606:4700:4700::1111', '8.8.8.8']]));

        $target = $guard->check('https://site.org/');

        $this->assertSame('8.8.8.8', $target->ip);
        $this->assertSame(443, $target->port);
    }

    private function assertBlocked(UrlGuard $guard, string $url, string $reason): void
    {
        try {
            $guard->check($url);
            $this->fail('Expected a blocked target for '.$url);
        } catch (BlockedTarget $blocked) {
            $this->assertSame($reason, $blocked->reason);
        }
    }
}
