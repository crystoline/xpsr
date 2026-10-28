<?php

declare(strict_types=1);

namespace Crystoline\HttpClient\Tests\Fixtures;

use RuntimeException;

/**
 * Runs tests/Fixtures/server.php in PHP's built-in server on a free local port. The server
 * handles one request at a time, so a test that requests /slow should use its own server.
 */
final class TestServer
{
    /** @var resource */
    private $process;

    public readonly string $url;

    public function __construct()
    {
        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            __DIR__,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the test server.');
        }
        $this->process = $process;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new RuntimeException("The test server did not start on port {$port}.");
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /** A local port nothing listens on, for connection failures. */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($socket === false) {
            throw new RuntimeException("Could not find a free port: {$message}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
