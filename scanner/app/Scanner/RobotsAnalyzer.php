<?php

namespace App\Scanner;

final class RobotsAnalyzer
{
    /**
     * @return array{disallow_all: bool, sitemaps: list<string>}
     */
    public function analyze(string $body): array
    {
        $sitemaps = [];
        $agents = [];
        $inRules = false;
        $disallowAll = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap' && $value !== '') {
                $sitemaps[] = $value;
            } elseif ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = $value;
            } elseif ($field === 'disallow' || $field === 'allow') {
                $inRules = true;
                if ($field === 'disallow' && $value === '/' && in_array('*', $agents, true)) {
                    $disallowAll = true;
                }
            }
        }

        return ['disallow_all' => $disallowAll, 'sitemaps' => array_slice(array_values(array_unique($sitemaps)), 0, 5)];
    }
}
