<?php

namespace App\Scanner\Http;

use App\Scanner\Support\Target;

/**
 * Single GET pinned to the guarded address. Redirects stay off so each hop is checked by SafeFetcher.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private int $maxBytes,
        private int $connectTimeout,
        private int $totalTimeout,
        private string $userAgent,
    ) {}

    public function get(Target $target): Response
    {
        $body = '';
        $headers = [];
        $truncated = false;
        $ip = str_contains($target->ip, ':') ? '['.$target->ip.']' : $target->ip;

        $curl = curl_init($target->url);
        curl_setopt_array($curl, [
            // Pinning closes the gap between the guard's lookup and the connection, which is DNS rebinding.
            CURLOPT_RESOLVE => [$target->host.':'.$target->port.':'.$ip],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->totalTimeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain,application/xml;q=0.9,*/*;q=0.8'],
            // The certificate is reported by the TLS probe. A bad one must not hide the rest of the checks.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$truncated): int {
                $room = $this->maxBytes - strlen($body);
                if ($room <= 0) {
                    $truncated = true;

                    return 0;
                }
                $body .= substr($chunk, 0, $room);

                return strlen($chunk);
            },
        ]);

        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        $message = curl_error($curl);

        $failed = $errno !== 0 && ! ($truncated && $errno === CURLE_WRITE_ERROR);

        return new Response($status, $headers, $body, $truncated, $failed ? $message : null);
    }
}
