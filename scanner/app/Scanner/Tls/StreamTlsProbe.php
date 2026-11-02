<?php

namespace App\Scanner\Tls;

use App\Scanner\Support\Target;

/**
 * Connects to the guarded address on port 443 and reads the leaf certificate.
 */
final class StreamTlsProbe implements TlsProbe
{
    public function __construct(private int $timeout) {}

    public function inspect(Target $target): array
    {
        $verified = $this->connect($target, true);
        if ($verified['certificate'] !== null) {
            return $this->describe($verified['certificate'], true, null);
        }

        // Read the certificate anyway so the report can still say when it expires.
        $unverified = $this->connect($target, false);
        if ($unverified['certificate'] !== null) {
            return $this->describe($unverified['certificate'], false, $verified['error']);
        }

        return ['valid' => false, 'days_remaining' => null, 'expires_at' => null, 'issuer' => null, 'error' => $verified['error']];
    }

    /**
     * @return array{certificate: mixed, error: ?string}
     */
    private function connect(Target $target, bool $verify): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => $target->host,
            'SNI_enabled' => true,
        ]]);

        $ip = str_contains($target->ip, ':') ? '['.$target->ip.']' : $target->ip;
        $socket = @stream_socket_client("ssl://{$ip}:443", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            $last = error_get_last();

            return ['certificate' => null, 'error' => $error !== '' ? $error : ($last['message'] ?? 'connection failed')];
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        return ['certificate' => $params['options']['ssl']['peer_certificate'] ?? null, 'error' => null];
    }

    /**
     * @return array{valid: bool, days_remaining: ?int, expires_at: ?string, issuer: ?string, error: ?string}
     */
    private function describe(mixed $certificate, bool $trusted, ?string $error): array
    {
        $parsed = openssl_x509_parse($certificate);
        if (! is_array($parsed)) {
            return ['valid' => false, 'days_remaining' => null, 'expires_at' => null, 'issuer' => null, 'error' => 'unreadable certificate'];
        }

        $expires = (int) $parsed['validTo_time_t'];

        return [
            'valid' => $trusted && $expires > time(),
            'days_remaining' => (int) floor(($expires - time()) / 86400),
            'expires_at' => gmdate('c', $expires),
            'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? null),
            'error' => $error,
        ];
    }
}
