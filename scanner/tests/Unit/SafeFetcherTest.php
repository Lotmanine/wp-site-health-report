<?php

namespace Tests\Unit;

use App\Scanner\Http\Response;
use App\Scanner\Http\SafeFetcher;
use App\Scanner\Http\Transport;
use App\Scanner\Support\BlockedTarget;
use App\Scanner\Support\Target;
use App\Scanner\Support\UrlGuard;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeResolver;
use Tests\Fakes\FakeTransport;

class SafeFetcherTest extends TestCase
{
    public function test_a_redirect_to_a_private_address_is_refused(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8'], 'inner.site.org' => ['10.0.0.5']]);
        $transport = new FakeTransport([
            'https://site.org/' => new Response(302, ['location' => 'https://inner.site.org/admin'], ''),
        ]);

        $this->expectBlocked($resolver, $transport, 'blocked_ip');
        $this->assertCount(1, $transport->requests);
    }

    public function test_a_redirect_to_an_ip_literal_is_refused(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8']]);
        $transport = new FakeTransport([
            'https://site.org/' => new Response(301, ['location' => 'http://169.254.169.254/latest/meta-data/'], ''),
        ]);

        $this->expectBlocked($resolver, $transport, 'blocked_host');
    }

    public function test_a_redirect_to_a_blocked_port_is_refused(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8']]);
        $transport = new FakeTransport([
            'https://site.org/' => new Response(302, ['location' => 'http://site.org:6379/'], ''),
        ]);

        $this->expectBlocked($resolver, $transport, 'blocked_port');
    }

    public function test_a_redirect_to_a_file_url_is_refused(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8']]);
        $transport = new FakeTransport([
            'https://site.org/' => new Response(302, ['location' => 'file:///etc/passwd'], ''),
        ]);

        $this->expectBlocked($resolver, $transport, 'invalid_url');
    }

    public function test_dns_rebinding_between_hops_is_caught(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8']]);
        $transport = new class($resolver) extends FakeTransportStub {};

        try {
            (new SafeFetcher(new UrlGuard($resolver), $transport))->fetch('https://site.org/');
            $this->fail('Expected the second hop to be blocked');
        } catch (BlockedTarget $blocked) {
            $this->assertSame('blocked_ip', $blocked->reason);
        }
    }

    public function test_too_many_redirects_stop_the_fetch(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8']]);
        $transport = new FakeTransport([
            'https://site.org/' => new Response(302, ['location' => '/1'], ''),
            'https://site.org/1' => new Response(302, ['location' => '/2'], ''),
            'https://site.org/2' => new Response(302, ['location' => '/3'], ''),
            'https://site.org/3' => new Response(302, ['location' => '/4'], ''),
        ]);

        $this->expectBlocked($resolver, $transport, 'too_many_redirects');
        $this->assertCount(4, $transport->requests);
    }

    public function test_every_hop_connects_to_the_checked_address(): void
    {
        $resolver = new FakeResolver(['site.org' => ['8.8.8.8'], 'www.site.org' => ['1.1.1.1']]);
        $transport = new FakeTransport([
            'https://site.org/' => new Response(301, ['location' => 'https://www.site.org/'], ''),
            'https://www.site.org/' => new Response(200, [], 'ok'),
        ]);

        $result = (new SafeFetcher(new UrlGuard($resolver), $transport))->fetch('https://site.org/');

        $this->assertSame('https://www.site.org/', $result['final_url']);
        $this->assertSame(['8.8.8.8', '1.1.1.1'], array_map(fn (Target $t) => $t->ip, $transport->requests));
    }

    private function expectBlocked(FakeResolver $resolver, FakeTransport $transport, string $reason): void
    {
        try {
            (new SafeFetcher(new UrlGuard($resolver), $transport))->fetch('https://site.org/');
            $this->fail('Expected a blocked target');
        } catch (BlockedTarget $blocked) {
            $this->assertSame($reason, $blocked->reason);
        }
    }
}

/**
 * First answer is a redirect to the same host. The record then changes to a private address.
 */
abstract class FakeTransportStub implements Transport
{
    public function __construct(private FakeResolver $resolver) {}

    public function get(Target $target): Response
    {
        $this->resolver->records['site.org'] = ['192.168.1.1'];

        return new Response(302, ['location' => '/next'], '');
    }
}
