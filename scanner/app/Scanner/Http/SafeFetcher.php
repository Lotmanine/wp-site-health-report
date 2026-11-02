<?php

namespace App\Scanner\Http;

use App\Scanner\Support\BlockedTarget;
use App\Scanner\Support\Target;
use App\Scanner\Support\UrlGuard;

/**
 * Follows redirects by hand so every hop goes back through the guard.
 */
final class SafeFetcher
{
    public const MAX_REDIRECTS = 3;

    public function __construct(private UrlGuard $guard, private Transport $transport) {}

    /**
     * @return array{final_url: string, target: Target, response: Response, redirects: list<string>}
     *
     * @throws BlockedTarget
     */
    public function fetch(string $url): array
    {
        $redirects = [];
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->guard->check($current);
            $response = $this->transport->get($target);

            if (! $response->isRedirect()) {
                return ['final_url' => $current, 'target' => $target, 'response' => $response, 'redirects' => $redirects];
            }

            $current = $this->absolute($current, $response->headers['location']);
            $redirects[] = $current;
        }

        throw new BlockedTarget('too_many_redirects');
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/\\');

        return $origin.$directory.'/'.$location;
    }
}
