<?php

namespace Tests\Unit;

use App\Scanner\HomepageAnalyzer;
use App\Scanner\RobotsAnalyzer;
use PHPUnit\Framework\TestCase;

class AnalyzerTest extends TestCase
{
    public function test_meta_data_is_read(): void
    {
        $html = '<html><head><title> My  Site </title><meta name="Description" content="About us">'
            .'<link rel="canonical" href="https://site.org/"><meta name="robots" content="noindex"></head></html>';

        $meta = (new HomepageAnalyzer)->analyze($html, [])['meta'];

        $this->assertSame('My Site', $meta['title']);
        $this->assertSame('About us', $meta['description']);
        $this->assertSame('https://site.org/', $meta['canonical']);
        $this->assertSame('noindex', $meta['robots']);
    }

    public function test_wordpress_is_fingerprinted_with_its_version(): void
    {
        $html = '<meta name="generator" content="WordPress 7.1.4"><link href="/wp-content/themes/x/style.css">';

        $wp = (new HomepageAnalyzer)->analyze($html, ['link' => '<https://site.org/wp-json/>; rel="https://api.w.org/"'])['wordpress'];

        $this->assertTrue($wp['detected']);
        $this->assertSame('7.1.4', $wp['version']);
        $this->assertSame(['generator', 'asset_paths', 'rest_link'], $wp['signals']);
    }

    public function test_other_platforms_are_not_flagged(): void
    {
        $wp = (new HomepageAnalyzer)->analyze('<html><title>Static site</title></html>', [])['wordpress'];

        $this->assertFalse($wp['detected']);
    }

    public function test_robots_sitemaps_and_block_all_are_found(): void
    {
        $parsed = (new RobotsAnalyzer)->analyze("User-agent: *\nDisallow: /\nSitemap: https://site.org/sitemap.xml\n");

        $this->assertTrue($parsed['disallow_all']);
        $this->assertSame(['https://site.org/sitemap.xml'], $parsed['sitemaps']);
    }

    public function test_a_block_for_one_bot_is_not_block_all(): void
    {
        $parsed = (new RobotsAnalyzer)->analyze("User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n");

        $this->assertFalse($parsed['disallow_all']);
    }
}
