<?php

namespace App\Scanner\Http;

use App\Scanner\Support\Target;

interface Transport
{
    public function get(Target $target): Response;
}
