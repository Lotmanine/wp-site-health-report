<?php

namespace Tests\Feature;

use App\Scanner\Http\CurlTransport;
use App\Scanner\Support\Target;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real transport against a local PHP server, so pinning and limits are tested for real.
 */
class CurlTransportTest extends TestCase
{
    /** @var resource */
    private $server;

    private int $port;

    private string $router;

    protected function setUp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->router = tempnam(sys_get_temp_dir(), 'router').'.php';
        file_put_contents($this->router, <<<'PHP'
<?php
header('X-Seen-Host: '.$_SERVER['HTTP_HOST']);
if ($_SERVER['REQUEST_URI'] === '/redirect') {
    header('Location: http://169.254.169.254/latest/', true, 302);
    exit;
}
if ($_SERVER['REQUEST_URI'] === '/large') {
    echo str_repeat('x', 5 * 1024 * 1024);
    exit;
}
if ($_SERVER['REQUEST_URI'] === '/slow') {
    sleep(5);
}
echo 'ok';
PHP);

        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $this->router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        set_error_handler(fn (): bool => true);
        for ($i = 0; $i < 50; $i++) {
            $probe = fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if ($probe) {
                fclose($probe);
                break;
            }
            usleep(100000);
        }
        restore_error_handler();
    }

    protected function tearDown(): void
    {
        proc_terminate($this->server);
        proc_close($this->server);
        @unlink($this->router);
    }

    private function target(string $path): Target
    {
        return new Target('http://pinned.site.org:'.$this->port.$path, 'http', 'pinned.site.org', $this->port, '127.0.0.1');
    }

    public function test_the_connection_goes_to_the_pinned_address_not_dns(): void
    {
        $response = (new CurlTransport(1024, 2, 3, 'test'))->get($this->target('/'));

        $this->assertSame(200, $response->status);
        $this->assertSame('ok', $response->body);
        $this->assertSame('pinned.site.org:'.$this->port, $response->headers['x-seen-host']);
    }

    public function test_redirects_are_returned_not_followed(): void
    {
        $response = (new CurlTransport(1024, 2, 3, 'test'))->get($this->target('/redirect'));

        $this->assertSame(302, $response->status);
        $this->assertTrue($response->isRedirect());
        $this->assertSame('http://169.254.169.254/latest/', $response->headers['location']);
    }

    public function test_the_body_is_cut_at_the_size_limit(): void
    {
        $response = (new CurlTransport(1024, 2, 5, 'test'))->get($this->target('/large'));

        $this->assertTrue($response->truncated);
        $this->assertLessThanOrEqual(1024, strlen($response->body));
        $this->assertNull($response->error);
    }

    public function test_a_slow_host_hits_the_time_limit(): void
    {
        $started = microtime(true);

        $response = (new CurlTransport(1024, 2, 1, 'test'))->get($this->target('/slow'));

        $this->assertNotNull($response->error);
        $this->assertLessThan(3, microtime(true) - $started);
    }
}
