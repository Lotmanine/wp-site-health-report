<?php

namespace App\Providers;

use App\Scanner\Http\CurlTransport;
use App\Scanner\Http\Transport;
use App\Scanner\Support\NativeResolver;
use App\Scanner\Support\Resolver;
use App\Scanner\Tls\StreamTlsProbe;
use App\Scanner\Tls\TlsProbe;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Resolver::class, NativeResolver::class);
        $this->app->bind(Transport::class, fn () => new CurlTransport(
            (int) config('scanner.max_bytes'),
            (int) config('scanner.connect_timeout'),
            (int) config('scanner.total_timeout'),
            (string) config('scanner.user_agent'),
        ));
        $this->app->bind(TlsProbe::class, fn () => new StreamTlsProbe((int) config('scanner.connect_timeout')));
    }

    public function boot(): void
    {
        //
    }
}
