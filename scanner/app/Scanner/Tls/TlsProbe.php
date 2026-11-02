<?php

namespace App\Scanner\Tls;

use App\Scanner\Support\Target;

interface TlsProbe
{
    /**
     * @return array{valid: bool, days_remaining: ?int, expires_at: ?string, issuer: ?string, error: ?string}
     */
    public function inspect(Target $target): array;
}
