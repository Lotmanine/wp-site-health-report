<?php

namespace App\Scanner\Http;

final readonly class Response
{
    /**
     * @param  array<string, string>  $headers  Lowercase names.
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public bool $truncated = false,
        public ?string $error = null,
    ) {}

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && isset($this->headers['location']);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }
}
