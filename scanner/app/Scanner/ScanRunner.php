<?php

namespace App\Scanner;

use App\Scanner\Http\SafeFetcher;
use App\Scanner\Support\BlockedTarget;
use App\Scanner\Support\Target;
use App\Scanner\Tls\TlsProbe;
use RuntimeException;

/**
 * The full passive scan: the page, robots.txt, one sitemap and the certificate. Nothing else.
 */
final class ScanRunner
{
    private const REPORTED_HEADERS = [
        'strict-transport-security', 'content-security-policy', 'content-security-policy-report-only',
        'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'x-robots-tag',
        'server',
    ];

    public function __construct(
        private SafeFetcher $fetcher,
        private TlsProbe $tls,
        private HomepageAnalyzer $homepage,
        private RobotsAnalyzer $robots,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws BlockedTarget
     * @throws RuntimeException
     */
    public function run(string $url): array
    {
        $home = $this->fetcher->fetch($url);
        $response = $home['response'];

        if ($response->error !== null) {
            throw new RuntimeException('fetch_failed');
        }

        $analysis = $this->homepage->analyze($response->body, $response->headers);
        $origin = $this->origin($home['final_url']);
        $robots = $this->robotsFile($origin);
        $https = str_starts_with($home['final_url'], 'https://');

        return [
            'final_url' => $home['final_url'],
            'status_code' => $response->status,
            'redirects' => $home['redirects'],
            'https' => $https,
            'truncated' => $response->truncated,
            'tls' => $https ? $this->tls->inspect($home['target']) : ['skipped' => true],
            'headers' => array_intersect_key($response->headers, array_flip(self::REPORTED_HEADERS)),
            'wordpress' => $analysis['wordpress'],
            'robots' => $robots,
            'sitemap' => $this->sitemap($origin, $robots['sitemaps'], $analysis['wordpress']['detected'], $home['target']),
            'meta' => $analysis['meta'],
        ];
    }

    /**
     * @return array{found: bool, status: int, disallow_all: bool, sitemaps: list<string>}
     */
    private function robotsFile(string $origin): array
    {
        try {
            $response = $this->fetcher->fetch($origin.'/robots.txt')['response'];
        } catch (BlockedTarget) {
            return ['found' => false, 'status' => 0, 'disallow_all' => false, 'sitemaps' => []];
        }

        $parsed = $response->ok() ? $this->robots->analyze($response->body) : ['disallow_all' => false, 'sitemaps' => []];

        return ['found' => $response->ok(), 'status' => $response->status] + $parsed;
    }

    /**
     * @param  list<string>  $declared
     * @return array{found: bool, url: ?string}
     */
    private function sitemap(string $origin, array $declared, bool $wordpress, Target $home): array
    {
        $sameHost = array_filter($declared, fn (string $url): bool => parse_url($url, PHP_URL_HOST) === $home->host);
        $candidates = array_merge(array_slice(array_values($sameHost), 0, 1), [$origin.'/sitemap.xml']);
        if ($wordpress) {
            $candidates[] = $origin.'/wp-sitemap.xml';
        }

        foreach (array_unique($candidates) as $candidate) {
            try {
                $response = $this->fetcher->fetch($candidate)['response'];
            } catch (BlockedTarget) {
                continue;
            }
            if ($response->ok() && str_contains($response->body, '<')) {
                return ['found' => true, 'url' => $candidate];
            }
        }

        return ['found' => false, 'url' => null];
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
