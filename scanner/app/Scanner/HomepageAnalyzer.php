<?php

namespace App\Scanner;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads meta data and WordPress signals from a page. Nothing here is executed or followed.
 */
final class HomepageAnalyzer
{
    /**
     * @param  array<string, string>  $headers
     * @return array{meta: array<string, string>, wordpress: array<string, mixed>}
     */
    public function analyze(string $html, array $headers): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $generator = $this->metaContent($xpath, 'generator');
        $signals = [];
        $version = null;

        if (preg_match('/WordPress\s*([0-9][0-9.]*)?/i', $generator, $match)) {
            $signals[] = 'generator';
            $version = $match[1] ?? null;
        }
        if (preg_match('#/wp-(content|includes)/#', $html)) {
            $signals[] = 'asset_paths';
        }
        if (str_contains($headers['link'] ?? '', 'api.w.org') || str_contains($html, 'api.w.org')) {
            $signals[] = 'rest_link';
        }

        return [
            'meta' => [
                'title' => $this->text($xpath, '//title'),
                'description' => $this->metaContent($xpath, 'description'),
                'canonical' => $this->attribute($xpath, '//link[@rel="canonical"]', 'href'),
                'robots' => $this->metaContent($xpath, 'robots'),
            ],
            'wordpress' => [
                'detected' => $signals !== [],
                'signals' => $signals,
                'version' => $version ?: null,
            ],
        ];
    }

    private function metaContent(DOMXPath $xpath, string $name): string
    {
        return $this->attribute($xpath, '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="'.$name.'"]', 'content');
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute): string
    {
        $node = $xpath->query($query)?->item(0);

        return $node instanceof DOMElement ? $this->clean($node->getAttribute($attribute)) : '';
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);

        return $node === null ? '' : $this->clean((string) $node->textContent);
    }

    private function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, 500);
    }
}
