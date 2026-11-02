<?php

namespace App\Scanner\Support;

use RuntimeException;

/**
 * Raised when a URL or a redirect hop must not be requested.
 */
class BlockedTarget extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
