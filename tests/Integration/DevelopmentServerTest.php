<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class DevelopmentServerTest extends TestCase
{
    private string $root;
    private array $files = [];
    private ?Process $server = null;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_signal') || DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The live server shutdown test requires Unix and pcntl.');
        }
        $project = dirname(__DIR__, 2);
        $this->root = sys_get_temp_dir() . '/xpsr console ' . bin2hex(random_bytes(8));
        foreach (['', '/bootstrap', '/config', '/routes', '/public'] as $directory) {
            mkdir($this->root . $directory);
        }
        $this->write('xpsr', file_get_contents($project . '/xpsr'));
        $this->write('bootstrap/app.php', '<?php require_once ' . var_export($project . '/vendor/autoload.php', true)
            . '; $app = new \Crystoline\Application(dirname(__DIR__)); $app->boot(); return $app;');
        $this->write('config/app.php', '<?php return ["registry" => [\Crystoline\Registry\FactoryRegistry::class, \Crystoline\Registry\RouteServiceRegistry::class, \Crystoline\Registry\ConsoleServiceRegistry::class]];');
        $this->write('config/factory.php', '<?php return require ' . var_export($project . '/config/factory.php', true) . ';');
        $this->write('config/routing.php', '<?php return ["files" => ["routes/web.php"]];');
        $this->write('routes/web.php', <<<'PHP'
<?php
return static function (\Crystoline\Routing\RouteCollection $routes): void {
    $routes->get('/hello.json', static fn() => (new \Crystoline\Http\Response\ResponseBuilder(new \Nyholm\Psr7\Factory\Psr17Factory(), new \Nyholm\Psr7\Factory\Psr17Factory()))->json(['ok' => true]));
};
PHP);
        $this->write('public/index.php', file_get_contents($project . '/public/index.php'));
        $this->write('public/asset file.txt', 'public asset');
        $this->write('public/.private.txt', 'hidden sentinel');
        $this->write('private.txt', 'outside sentinel');
        $this->write('composer.json', '{"private":"outside sentinel"}');
        $this->files[] = $this->root . '/public/link.txt';
        symlink($this->root . '/private.txt', $this->root . '/public/link.txt');
    }

    protected function tearDown(): void
    {
        $this->server?->stop(2);
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (isset($this->root)) {
            foreach (['/public', '/routes', '/config', '/bootstrap', ''] as $directory) {
                rmdir($this->root . $directory);
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        $full = $this->root . '/' . $path;
        file_put_contents($full, $contents);
        $this->files[] = $full;
    }

    /** @return array{int, string} */
    private function request(int $port, string $path): array
    {
        $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 2],
        ]));
        preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $match);
        return [(int) $match[1], $body];
    }

    public static function terminationSignals(): iterable
    {
        yield 'Ctrl+C' => [2];
        yield 'termination' => [15];
    }

    #[DataProvider('terminationSignals')]
    public function testServeHandlesRoutesAndPublicFilesAndStopsItsChild(int $signal): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, $this->root . '/xpsr', 'serve', '--port', (string) $port, '--no-interaction'], sys_get_temp_dir(), timeout: 15);
        $this->server->start();
        self::assertTrue($this->server->waitUntil(static fn(string $type, string $buffer) => str_contains($buffer, 'Development Server') && str_contains($buffer, 'started')));
        self::assertSame([200, '{"ok":true}'], $this->request($port, '/hello.json'));
        self::assertSame([200, 'public asset'], $this->request($port, '/asset%20file.txt'));
        foreach (['/missing', '/composer.json', '/private.txt', '/.private.txt', '/link.txt', '/%2e%2e/private.txt'] as $path) {
            [$status, $body] = $this->request($port, $path);
            self::assertSame(404, $status, $path);
            self::assertStringNotContainsString('sentinel', $body, $path);
        }
        $this->server->signal($signal);
        self::assertSame(128 + $signal, $this->server->wait());
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.5);
        if (is_resource($connection)) {
            fclose($connection);
        }
        self::assertFalse($connection, 'The child server must stop with the console command.');
    }

    public function testServeReportsPortConflictWithNonZeroStatus(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        try {
            $address = stream_socket_get_name($socket, false);
            $port = substr($address, strrpos($address, ':') + 1);
            $this->server = new Process([PHP_BINARY, $this->root . '/xpsr', 'serve', '--port', $port, '--no-interaction'], sys_get_temp_dir(), timeout: 10);
            self::assertSame(1, $this->server->run());
            self::assertStringContainsString('Failed to listen', $this->server->getErrorOutput());
        } finally {
            fclose($socket);
        }
    }
}
