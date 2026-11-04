<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\ConfigurationCache;
use FilesystemIterator;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Stringable;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\Fixtures\Console\BufferedConsoleOutput;

/** config:cache and config:clear on a copy of the skeleton's configuration, and what the application does with the cache. */
final class ConfigCacheCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $skeleton = dirname(__DIR__, 2);
        $this->root = sys_get_temp_dir() . '/xpsr-config-cache-' . bin2hex(random_bytes(4));
        foreach (['config', 'config/testing', 'routes'] as $directory) {
            if (!is_dir($skeleton . '/' . $directory)) {
                continue;
            }
            mkdir($this->root . '/' . $directory, 0777, true);
            foreach (glob($skeleton . '/' . $directory . '/*.php') ?: [] as $file) {
                copy($file, $this->root . '/' . $directory . '/' . basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testConfigCacheWritesTheCacheAndTheApplicationReadsIt(): void
    {
        $app = new Application($this->root);
        self::assertSame('testing', $app->getEnvironment());
        self::assertNull($app->getConfigurationNotice());

        [$status, $output] = $this->console($app, 'config:cache');
        $text = $output->fetch();
        self::assertSame(0, $status, $output->errors());
        self::assertStringContainsString('Configuration cached in var/cache/config.php for APP_ENV=testing: ', $text);
        self::assertStringContainsString('Read from the files on each request, because they hold closures or objects: config/factory.php.', $text);
        self::assertFileExists($this->root . '/' . ConfigurationCache::PATH);

        $cached = new Application($this->root);
        self::assertNull($cached->getConfigurationNotice());
        $live = $app->getConfig()->all();
        $fromCache = array_merge($cached->getConfig()->all(), ['factory' => $live['factory']]);
        ksort($live);
        ksort($fromCache);
        self::assertSame($live, $fromCache, 'The cached configuration equals the one read from the files; only the closures differ as objects.');
        self::assertIsCallable($cached->getConfig()->get('factory.' . LoggerInterface::class));
        [, $output] = $this->console($cached, 'about', ['--json' => true]);
        self::assertSame('cached', json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)['configuration']);
    }

    public function testAChangedFileSetsTheCacheAsideWithANoticeUntilConfigCacheRunsAgain(): void
    {
        $this->console(new Application($this->root), 'config:cache');
        file_put_contents($this->root . '/config/app.php', "\n// edited after config:cache\n", FILE_APPEND);

        $edited = new Application($this->root);
        self::assertSame(
            'var/cache/config.php no longer matches config/app.php. The configuration files were used instead; run "php xpsr config:cache" again, or "php xpsr config:clear".',
            $edited->getConfigurationNotice(),
        );
        [$status, $output] = $this->console($edited, 'about', ['--json' => true]);
        self::assertSame(0, $status);
        self::assertStringContainsString('no longer matches config/app.php', $output->errors(), 'Every command reports the notice on stderr.');
        self::assertSame('files', json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)['configuration']);

        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $edited->getContainer()->instance(LoggerInterface::class, $logger);
        self::assertSame(200, $edited->getHttpKernel()->handle(new ServerRequest('GET', '/hello'))->getStatusCode());
        self::assertSame([['warning', $edited->getConfigurationNotice()]], $logger->records, 'A request logs the notice once.');

        [$status] = $this->console($edited, 'config:cache');
        self::assertSame(0, $status);
        self::assertNull((new Application($this->root))->getConfigurationNotice());
    }

    public function testACacheForAnotherEnvironmentIsSetAside(): void
    {
        $this->console(new Application($this->root), 'config:cache');
        $_ENV['APP_ENV'] = 'production';
        $app = new Application($this->root);

        self::assertSame('production', $app->getEnvironment());
        self::assertSame(
            'var/cache/config.php was built for APP_ENV=testing, but APP_ENV is production. The configuration files were used instead; run "php xpsr config:cache" again, or "php xpsr config:clear".',
            $app->getConfigurationNotice(),
        );
    }

    public function testConfigClearRemovesTheCache(): void
    {
        $app = new Application($this->root);
        [, $output] = $this->console($app, 'config:clear');
        self::assertSame('There is no configuration cache (var/cache/config.php).', trim($output->fetch()));

        $this->console($app, 'config:cache');
        [$status, $output] = $this->console($app, 'config:clear');
        self::assertSame(0, $status);
        self::assertSame('Removed var/cache/config.php. The configuration files are read on each request.', trim($output->fetch()));
        self::assertFileDoesNotExist($this->root . '/' . ConfigurationCache::PATH);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, BufferedConsoleOutput}
     */
    private function console(Application $app, string $command, array $options = []): array
    {
        $output = new BufferedConsoleOutput();
        $status = $app->getConsoleKernel()->run(new ArrayInput(['command' => $command, ...$options]), $output);

        return [$status, $output];
    }
}
