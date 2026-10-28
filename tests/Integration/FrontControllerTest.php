<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Console\Setup\BootstrapCheck;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\SkeletonProject;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;

/**
 * Serves a skeleton copy with PHP's built-in server and talks raw HTTP to public/index.php.
 */
final class FrontControllerTest extends TestCase
{
    private string $root;
    private ?Process $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->root = SkeletonProject::create();
        file_put_contents($this->root . '/config/routing.php', "<?php\n\nreturn ['adapter' => 'fastroute', 'files' => ['routes/api.php', 'routes/test.php']];\n");
        file_put_contents($this->root . '/routes/test.php', <<<'PHP'
<?php

use Crystoline\Xpsr\Routing\RouteCollection;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Crystoline\Xpsr\Tests\Fixtures\Http\ChunkedStream;

return static function (RouteCollection $routes): void {
    $routes->get('/headers', static fn() => (new Response(201, ['X-Multi' => ['a', 'b']], 'made', '1.1', 'Made'))
        ->withAddedHeader('Set-Cookie', 'first=1; HttpOnly')
        ->withAddedHeader('Set-Cookie', 'second=2'));
    $routes->get('/redirect', static fn() => new Response(301, ['Location' => '/headers']));
    $routes->get('/empty', static fn() => new Response(204, [], 'must not be sent'));
    $routes->get('/stream', static fn() => new Response(200, ['Content-Type' => 'text/plain'], new ChunkedStream(['one ', 'two ', 'three'])));
    $routes->add(['POST', 'PUT', 'PATCH'], '/echo', static fn(ServerRequestInterface $request) => new Response(
        200,
        ['Content-Type' => 'application/json'],
        json_encode(['method' => $request->getMethod(), 'parsed' => $request->getParsedBody(), 'raw' => (string) $request->getBody()]),
    ));
    $routes->get('/fail', static function (): never {
        throw new RuntimeException('secret detail');
    });
    $routes->get('/early-output', static function () {
        echo 'stray';

        return new Response(200, [], 'body');
    });
};
PHP);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(2);
        TemporaryDirectory::remove($this->root);
    }

    /** @param array<string, string> $environment */
    /**
     * @param array<string, string> $environment
     * @param array<string, string> $ini settings passed with -d. Output buffering is pinned so
     *                                   results do not depend on the machine's php.ini.
     */
    private function startServer(array $environment = [], array $ini = []): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $settings = [];
        foreach ($ini + ['output_buffering' => '0', 'display_errors' => '0'] as $name => $value) {
            array_push($settings, '-d', "{$name}={$value}");
        }
        $this->server = new Process(
            [PHP_BINARY, ...$settings, '-S', '127.0.0.1:' . $this->port, '-t', $this->root . '/public', dirname(__DIR__, 2) . '/packages/framework/src/Console/Resources/server.php'],
            $this->root,
            $environment + ['APP_DEBUG' => 'false'] + BootstrapCheck::environment(),
            timeout: 30,
        );
        $this->server->start();
        self::assertTrue($this->server->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'started')));
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: string, headers: list<string>, body: string}
     */
    private function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 5);
        if ($socket === false) {
            throw new RuntimeException("Could not connect: {$error}");
        }
        $lines = ["{$method} {$path} HTTP/1.1", 'Host: 127.0.0.1', 'Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        if ($body !== null) {
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n" . $body);
        stream_set_timeout($socket, 10);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        [$head, $content] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
        $headerLines = explode("\r\n", $head);
        $status = (string) array_shift($headerLines);
        if (in_array('transfer-encoding: chunked', array_map('strtolower', $headerLines), true)) {
            $content = self::decodeChunked($content);
        }

        return ['status' => $status, 'headers' => $headerLines, 'body' => $content];
    }

    private static function decodeChunked(string $content): string
    {
        $decoded = '';
        while (($end = strpos($content, "\r\n")) !== false) {
            $size = hexdec(substr($content, 0, $end));
            if ($size === 0) {
                break;
            }
            $decoded .= substr($content, $end + 2, (int) $size);
            $content = substr($content, $end + 2 + (int) $size + 2);
        }

        return $decoded;
    }

    /** @param list<string> $headers */
    private static function headerValues(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as $line) {
            [$key, $value] = explode(':', $line, 2) + [1 => ''];
            if (strcasecmp($key, $name) === 0) {
                $values[] = trim($value);
            }
        }

        return $values;
    }

    private function waitForLog(string $needle): string
    {
        $deadline = microtime(true) + 5;
        do {
            $log = (string) $this->server?->getErrorOutput();
            if (str_contains($log, $needle)) {
                return $log;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        self::fail("The server log does not contain \"{$needle}\":\n{$log}");
    }

    public function testResponsesAreEmittedThroughTheFrontController(): void
    {
        $this->startServer();

        $response = $this->request('GET', '/headers');
        self::assertSame('HTTP/1.1 201 Made', $response['status']);
        self::assertSame(['a', 'b'], self::headerValues($response['headers'], 'X-Multi'));
        self::assertSame(['first=1; HttpOnly', 'second=2'], self::headerValues($response['headers'], 'Set-Cookie'));
        self::assertSame('made', $response['body']);

        $redirect = $this->request('GET', '/redirect');
        self::assertSame('HTTP/1.1 301 Moved Permanently', $redirect['status'], 'PHP must not turn Location into 302.');
        self::assertSame(['/headers'], self::headerValues($redirect['headers'], 'Location'));

        $empty = $this->request('GET', '/empty');
        self::assertSame('HTTP/1.1 204 No Content', $empty['status']);
        self::assertSame('', $empty['body']);

        $stream = $this->request('GET', '/stream');
        self::assertSame('HTTP/1.1 200 OK', $stream['status']);
        self::assertSame('one two three', $stream['body']);

        $head = $this->request('HEAD', '/hello');
        self::assertSame('HTTP/1.1 200 OK', $head['status']);
        self::assertSame(['application/json'], self::headerValues($head['headers'], 'Content-Type'));
        self::assertSame('', $head['body']);

        $missing = $this->request('GET', '/missing', ['Accept' => 'application/json']);
        self::assertSame('HTTP/1.1 404 Not Found', $missing['status']);
        self::assertSame(['message' => 'Not Found'], json_decode($missing['body'], true));
    }

    public function testRequestBodiesAreParsed(): void
    {
        $this->startServer();
        $echo = fn(string $method, string $type, string $body): array => json_decode(
            $this->request($method, '/echo', ['Content-Type' => $type], $body)['body'],
            true,
        );

        self::assertSame(['name' => 'Ada', 'tags' => ['a', 'b']], $echo('POST', 'application/x-www-form-urlencoded', 'name=Ada&tags[]=a&tags[]=b')['parsed']);
        self::assertSame(['name' => 'Ada'], $echo('PUT', 'application/x-www-form-urlencoded', 'name=Ada')['parsed']);
        $json = $echo('PATCH', 'application/json; charset=utf-8', '{"name":"Ada","nested":{"ok":true}}');
        self::assertSame(['name' => 'Ada', 'nested' => ['ok' => true]], $json['parsed']);
        self::assertSame('{"name":"Ada","nested":{"ok":true}}', $json['raw'], 'Handlers can still read the raw body.');
        $multipart = "--xpsr\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--xpsr--\r\n";
        self::assertSame(['name' => 'Ada'], $echo('POST', 'multipart/form-data; boundary=xpsr', $multipart)['parsed']);

        $invalid = $this->request('POST', '/echo', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], '{"name":');
        self::assertSame('HTTP/1.1 400 Bad Request', $invalid['status']);
        self::assertSame(['message' => 'Bad Request'], json_decode($invalid['body'], true));
    }

    public function testProductionErrorsHideDetailsAndAreLogged(): void
    {
        $this->startServer();
        $html = $this->request('GET', '/fail');
        self::assertSame('HTTP/1.1 500 Internal Server Error', $html['status']);
        self::assertSame(['text/html; charset=utf-8'], self::headerValues($html['headers'], 'Content-Type'));
        self::assertSame('<h1>Internal Server Error</h1>', $html['body']);

        $json = $this->request('GET', '/fail', ['Accept' => 'application/json']);
        self::assertSame('HTTP/1.1 500 Internal Server Error', $json['status']);
        self::assertSame(['message' => 'Internal Server Error'], json_decode($json['body'], true));

        $log = $this->waitForLog('[ERROR] secret detail');
        self::assertStringContainsString('RuntimeException: secret detail', $log, 'The log includes the exception and its trace.');
    }

    public function testDebugErrorsShowDetails(): void
    {
        $this->startServer(['APP_DEBUG' => 'true']);
        $json = $this->request('GET', '/fail', ['Accept' => 'application/json']);
        self::assertSame('HTTP/1.1 500 Internal Server Error', $json['status']);
        self::assertSame(['message' => 'secret detail'], json_decode($json['body'], true));
        $html = $this->request('GET', '/fail');
        self::assertStringContainsString('secret detail', $html['body']);
    }

    public function testBootstrapFailureReturnsAPlainServerErrorAndLogsTheCause(): void
    {
        $this->startServer(['APP_ENV' => '../invalid']);
        $response = $this->request('GET', '/hello');
        self::assertSame('HTTP/1.1 500 Internal Server Error', $response['status']);
        self::assertSame(['text/plain; charset=utf-8'], self::headerValues($response['headers'], 'Content-Type'));
        self::assertSame('Internal Server Error', $response['body']);
        $this->waitForLog('APP_ENV must contain only');
    }

    public function testOutputBeforeEmissionIsLogged(): void
    {
        $this->startServer();
        $response = $this->request('GET', '/early-output');
        // Headers went out with the stray output, so the real response can no longer be sent.
        self::assertSame('stray', $response['body']);
        $this->waitForLog('Cannot send the response: output already started (sent at');
    }

    public function testBufferedOutputBeforeEmissionBecomesACleanServerError(): void
    {
        // The production php.ini buffers output, so stray output is held back instead of sent.
        $this->startServer(ini: ['output_buffering' => '4096']);
        $response = $this->request('GET', '/early-output');

        self::assertSame('HTTP/1.1 500 Internal Server Error', $response['status']);
        self::assertSame('Internal Server Error', $response['body'], 'The stray output is discarded, not sent.');
        $this->waitForLog('Cannot send the response: output already started (5 bytes in an output buffer)');
    }
}
