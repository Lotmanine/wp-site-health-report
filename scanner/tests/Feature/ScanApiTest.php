<?php

namespace Tests\Feature;

use App\Jobs\RunScan;
use App\Models\Scan;
use App\Scanner\Http\Response;
use App\Scanner\Http\Transport;
use App\Scanner\Support\Resolver;
use App\Scanner\Support\Target;
use App\Scanner\Tls\TlsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeResolver;
use Tests\Fakes\FakeTransport;
use Tests\TestCase;

class ScanApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key-not-real';

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scanner.api_key' => self::KEY]);

        $this->transport = new FakeTransport;
        $this->app->instance(Transport::class, $this->transport);
        $this->app->instance(Resolver::class, new FakeResolver([
            'site.org' => ['8.8.8.8'],
            'inner.site.org' => ['10.0.0.5'],
        ]));
        $this->app->instance(TlsProbe::class, new class implements TlsProbe
        {
            public function inspect(Target $target): array
            {
                return ['valid' => true, 'days_remaining' => 60, 'expires_at' => '2027-01-01T00:00:00+00:00', 'issuer' => 'Test CA', 'error' => null];
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['X-Api-Key' => self::KEY];
    }

    public function test_requests_without_a_key_are_refused(): void
    {
        $this->postJson('/api/v1/scans', ['scan_ref' => 'a', 'url' => 'https://site.org/'])->assertStatus(401);
        $this->getJson('/api/v1/scans/anything')->assertStatus(401);
    }

    public function test_a_wrong_key_is_refused(): void
    {
        $this->postJson('/api/v1/scans', ['scan_ref' => 'a', 'url' => 'https://site.org/'], ['X-Api-Key' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_an_empty_configured_key_refuses_everything(): void
    {
        config(['scanner.api_key' => '']);

        $this->postJson('/api/v1/scans', ['scan_ref' => 'a', 'url' => 'https://site.org/'], ['X-Api-Key' => ''])
            ->assertStatus(401);
    }

    public function test_a_scan_is_queued_and_not_run_inline(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-1', 'url' => 'https://site.org/'], $this->auth())
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(RunScan::class);
        $this->assertSame([], $this->transport->requests);
    }

    public function test_a_retried_reference_does_not_start_a_second_scan(): void
    {
        Queue::fake();

        $first = $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-1', 'url' => 'https://site.org/'], $this->auth());
        $second = $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-1', 'url' => 'https://site.org/'], $this->auth());

        $this->assertSame($first->json('id'), $second->json('id'));
        Queue::assertPushed(RunScan::class, 1);
    }

    public function test_private_targets_are_refused_at_the_door(): void
    {
        $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-2', 'url' => 'https://inner.site.org/'], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error', 'blocked_ip');

        $this->assertSame(0, Scan::count());
    }

    public function test_a_full_scan_reports_every_check(): void
    {
        $html = '<html><head><title>Example title here</title><meta name="description" content="A description">'
            .'<link rel="canonical" href="https://site.org/"><meta name="generator" content="WordPress 7.1.4"></head></html>';

        $this->transport->responses = [
            'https://site.org/' => new Response(200, ['strict-transport-security' => 'max-age=31536000', 'set-cookie' => 'private=1'], $html),
            'https://site.org/robots.txt' => new Response(200, [], "User-agent: *\nDisallow:\nSitemap: https://site.org/sitemap_index.xml\n"),
            'https://site.org/sitemap_index.xml' => new Response(200, [], '<sitemapindex></sitemapindex>'),
        ];

        $id = $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-3', 'url' => 'https://site.org/'], $this->auth())->json('id');

        $response = $this->getJson('/api/v1/scans/'.$id, $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('result.https', true)
            ->assertJsonPath('result.tls.days_remaining', 60)
            ->assertJsonPath('result.wordpress.detected', true)
            ->assertJsonPath('result.wordpress.version', '7.1.4')
            ->assertJsonPath('result.robots.found', true)
            ->assertJsonPath('result.sitemap.found', true)
            ->assertJsonPath('result.sitemap.url', 'https://site.org/sitemap_index.xml')
            ->assertJsonPath('result.meta.title', 'Example title here')
            ->assertJsonPath('result.headers.strict-transport-security', 'max-age=31536000');

        $this->assertArrayNotHasKey('set-cookie', $response->json('result.headers'));
    }

    public function test_only_passive_paths_are_requested(): void
    {
        $this->transport->responses = ['https://site.org/' => new Response(200, [], '<title>Plain</title>')];

        $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-4', 'url' => 'https://site.org/'], $this->auth());

        $this->assertSame(
            ['https://site.org/', 'https://site.org/robots.txt', 'https://site.org/sitemap.xml'],
            array_map(fn (Target $target) => $target->url, $this->transport->requests)
        );
    }

    public function test_a_redirect_into_a_private_network_fails_the_scan(): void
    {
        $this->transport->responses = [
            'https://site.org/' => new Response(302, ['location' => 'https://inner.site.org/'], ''),
        ];

        $id = $this->postJson('/api/v1/scans', ['scan_ref' => 'ref-5', 'url' => 'https://site.org/'], $this->auth())->json('id');

        $this->getJson('/api/v1/scans/'.$id, $this->auth())
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'blocked_ip');
    }

    public function test_old_scans_are_pruned(): void
    {
        $old = Scan::create(['scan_ref' => 'old', 'url' => 'https://site.org/']);
        Scan::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);

        $this->transport->responses = ['https://site.org/' => new Response(200, [], '<title>x</title>')];
        $this->postJson('/api/v1/scans', ['scan_ref' => 'new', 'url' => 'https://site.org/'], $this->auth());

        $this->assertNull(Scan::find($old->id));
    }
}
