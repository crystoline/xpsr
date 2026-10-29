<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Tests;

use Composer\Autoload\ClassLoader;
use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Lock\LockServiceRegistry;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Processes that count against one key at the same moment never get past the limit together.
 * Set XPSR_TEST_REDIS_URLS (redis://host:port, comma-separated) to also count in Redis, with
 * locks on one Redis server and with Redlock across all of them.
 */
final class RateLimitConcurrencyTest extends TestCase
{
    private const PROCESSES = 4;

    private const LIMIT = 20;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr rate race ' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{array<string, mixed>, ?string}> */
    public static function stores(): iterable
    {
        yield 'file cache, file locks' => [['cache' => ['driver' => 'file']], null];

        $urls = getenv('XPSR_TEST_REDIS_URLS');
        if (!is_string($urls) || $urls === '') {
            return;
        }
        $servers = array_map(trim(...), explode(',', $urls));
        $redis = ['driver' => 'service', 'service' => 'cache.redis'];
        $registry = [CacheServiceRegistry::class, LockServiceRegistry::class];
        yield 'redis cache, redis locks' => [['app.registry' => $registry, 'cache' => $redis, 'lock' => ['store' => $servers[0]]], $servers[0]];
        if (count($servers) >= 3) {
            yield 'redis cache, redlock' => [['app.registry' => $registry, 'cache' => $redis, 'lock' => ['store' => 'redlock', 'redlock' => $servers]], $servers[0]];
        }
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('stores')]
    public function testParallelAttemptsNeverExceedTheLimit(array $config, ?string $redis): void
    {
        $settings = json_encode([
            'config' => $config + [
                'app.registry' => [CacheServiceRegistry::class],
                'rate_limit' => ['limits' => ['burst' => ['limit' => self::LIMIT, 'interval' => '10 minutes']]],
            ],
            'redis' => $redis,
            'key' => 'race:' . bin2hex(random_bytes(6)),
            'attempts' => self::LIMIT,
        ], JSON_THROW_ON_ERROR);
        $autoload = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        // Every process boots first, then all start counting at the same moment.
        $start = sprintf('%.6F', microtime(true) + 1.5);

        $workers = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $command = [PHP_BINARY, __DIR__ . '/Fixtures/rate-limit-worker.php', $autoload, $this->root, $settings, $start];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $workers[] = [$process, $pipes];
        }

        $accepted = 0;
        foreach ($workers as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertMatchesRegularExpression('/\A\d+\z/', $output, $errors . $output);
            $accepted += (int) $output;
        }

        self::assertSame(self::LIMIT, $accepted, sprintf('%d processes made %d attempts each; exactly the limit may be accepted.', self::PROCESSES, self::LIMIT));
    }
}
