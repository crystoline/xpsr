<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Console\Setup\BootstrapCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\SkeletonProject;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;

/**
 * Serves a skeleton copy with debug off and the default app.view_cache_dir. PHP's built-in server
 * starts every request fresh, as production does, so a template compiled by one request is read
 * back from the cache by the next.
 */
final class ViewCacheTest extends TestCase
{
    private string $root;
    private ?Process $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->root = SkeletonProject::create();
        file_put_contents($this->root . '/config/routing.php', "<?php\n\nreturn ['adapter' => 'fastroute', 'files' => ['routes/test.php']];\n");
        file_put_contents($this->root . '/routes/test.php', <<<'PHP'
<?php

use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Nyholm\Psr7\Response;

return static function (RouteCollection $routes): void {
    $routes->get('/probe', static fn() => new Response(200, [], trim(app()->get(ViewHandlerInterface::class)->render('probe'))));
};
PHP);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(2);
        TemporaryDirectory::remove($this->root);
    }

    /** @return iterable<string, array{string, string, bool}> engine, template extension, whether it compiles */
    public static function engines(): iterable
    {
        yield 'twig' => ['twig', 'twig', true];
        yield 'latte' => ['latte', 'latte', true];
        yield 'blade' => ['blade', 'blade.php', true];
        yield 'plates' => ['plates', 'php', false];
    }

    #[DataProvider('engines')]
    public function testAChangedTemplateIsCompiledAgainWithDebugOff(string $engine, string $extension, bool $compiles): void
    {
        $template = "{$this->root}/views/probe.{$extension}";
        $cache = $this->root . '/var/cache/views';
        file_put_contents($template, 'first');
        $this->startServer(['VIEW_ENGINE' => $engine]);

        self::assertSame('first', $this->get('/probe'));
        if (!$compiles) {
            self::assertDirectoryDoesNotExist($cache);
            $this->changeTemplate($template, 'second', $cache);
            self::assertSame('second', $this->get('/probe'));

            return;
        }
        self::assertNotSame([], TemporaryDirectory::files($cache), 'Compiled into var/cache/views, the default.');
        self::assertSame('first', $this->get('/probe'), 'Read back from the cache.');

        $this->changeTemplate($template, 'second', $cache);
        self::assertSame('second', $this->get('/probe'), 'A deploy that changes a template must not serve the old one.');

        $clear = new Process([PHP_BINARY, $this->root . '/xpsr', 'view:clear', '--no-interaction'], $this->root, ['VIEW_ENGINE' => $engine] + BootstrapCheck::environment(), timeout: 10);
        self::assertSame(0, $clear->run(), $clear->getErrorOutput());
        self::assertMatchesRegularExpression('/^Removed \d+ file\(s\) from the view cache in var\/cache\/views\.$/', trim($clear->getOutput()));
        self::assertSame([], TemporaryDirectory::files($cache));
        self::assertSame('second', $this->get('/probe'), 'Compiled again after view:clear.');
    }

    /** Rewrites the template with a time after every compiled file, as a deploy does. */
    private function changeTemplate(string $template, string $content, string $cache): void
    {
        $compiledAt = time() - 60;
        foreach (is_dir($cache) ? TemporaryDirectory::files($cache) : [] as $file) {
            touch("{$cache}/{$file}", $compiledAt);
        }
        file_put_contents($template, $content);
        touch($template, $compiledAt + 30);
        clearstatcache();
    }

    /** @param array<string, string> $environment */
    private function startServer(array $environment): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $this->server = new Process(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->port, '-t', $this->root . '/public', dirname(__DIR__, 2) . '/packages/framework/src/Console/Resources/server.php'],
            $this->root,
            $environment + ['APP_DEBUG' => 'false'] + BootstrapCheck::environment(),
            timeout: 30,
        );
        $this->server->start();
        self::assertTrue($this->server->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'started')));
    }

    private function get(string $path): string
    {
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
        if ($body === false) {
            throw new RuntimeException("Could not request {$path}.");
        }
        $status = http_get_last_response_headers()[0] ?? '';
        if (!str_contains($status, ' 200 ')) {
            self::fail("{$path} answered {$status}: {$body}\n" . $this->server?->getErrorOutput());
        }

        return $body;
    }
}
