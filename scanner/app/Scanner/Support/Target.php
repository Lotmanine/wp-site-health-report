<?php

namespace App\Scanner\Support;

/**
 * A URL that passed the guard, with the checked address it must connect to.
 */
final readonly class Target
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}
}
