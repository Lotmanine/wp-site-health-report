<?php

namespace Tests\Fakes;

use App\Scanner\Http\Response;
use App\Scanner\Http\Transport;
use App\Scanner\Support\Target;

final class FakeTransport implements Transport
{
    /** @var list<Target> */
    public array $requests = [];

    /**
     * @param  array<string, Response>  $responses
     */
    public function __construct(public array $responses = []) {}

    public function get(Target $target): Response
    {
        $this->requests[] = $target;

        return $this->responses[$target->url] ?? new Response(404, [], '');
    }
}
