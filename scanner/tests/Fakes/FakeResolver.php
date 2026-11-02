<?php

namespace Tests\Fakes;

use App\Scanner\Support\Resolver;

final class FakeResolver implements Resolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(public array $records = []) {}

    public function addresses(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
