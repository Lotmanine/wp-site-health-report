<?php

namespace App\Scanner\Support;

interface Resolver
{
    /**
     * Returns every A and AAAA address for a host.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
