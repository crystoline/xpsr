<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Cache\Console\CacheClearCommand;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as InvalidKeyException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheServiceRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr cache ' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string, mixed> $cache configuration for config/cache.php */
    private function container(array $cache = []): ServiceContainer
    {
        $config = new Configuration();
        $config->set('cache', $cache);
        $container = new ServiceContainer();
        $container->instance(Configuration::class, $config);
        $container->instance(Application::class, new Application($this->root));
        $registry = new CacheServiceRegistry($container);
        $registry->register();
        $registry->boot();

        return $container;
    }

    private function files(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        return iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)));
    }

    public function testFileCacheIsTheDefaultAndPersistsAcrossContainers(): void
    {
        $cache = $this->container()->get(CacheInterface::class);
        self::assertTrue($cache->set('user.1', ['name' => 'Ada']));
        self::assertSame(['name' => 'Ada'], $cache->get('user.1'));
        self::assertGreaterThan(0, $this->files($this->root . '/var/cache/app'), 'Items are stored under var/cache/app.');

        $later = $this->container()->get(CacheInterface::class);
        self::assertSame(['name' => 'Ada'], $later->get('user.1'), 'A new process sees stored items.');
        self::assertTrue($later->delete('user.1'));
        self::assertNull($later->get('user.1'));
        self::assertSame('fallback', $later->get('user.1', 'fallback'));
    }

    public function testPsr6AndPsr16ShareStorage(): void
    {
        $container = $this->container(['driver' => 'array']);
        $container->get(CacheInterface::class)->set('shared', 42);
        $item = $container->get(CacheItemPoolInterface::class)->getItem('shared');

        self::assertTrue($item->isHit());
        self::assertSame(42, $item->get());
        self::assertSame($container->get(CacheInterface::class), $container->get(CacheInterface::class), 'The cache is shared.');
    }

    public function testExpiredAndInvalidItems(): void
    {
        $cache = $this->container(['driver' => 'array'])->get(CacheInterface::class);
        $cache->set('gone', 'value', 0);
        self::assertFalse($cache->has('gone'), 'A TTL of zero or less removes the item.');

    }

    public function testReservedKeyCharactersAreRejectedOnlyWhenAssertionsRun(): void
    {
        // Symfony Cache validates keys through assert(), for speed. With assertions on (the
        // development default), reserved characters are rejected as PSR-16 requires. With
        // zend.assertions=-1 (the production php.ini), they are accepted and still work.
        $cache = $this->container()->get(CacheInterface::class);
        $rejected = null;
        try {
            $cache->set('invalid{key}', 'value');
        } catch (\Throwable $exception) {
            $rejected = $exception;
        }
        if (ini_get('zend.assertions') === '1') {
            // psr/simple-cache 1.x exception interfaces do not extend Throwable, so check by instanceof.
            self::assertInstanceOf(InvalidKeyException::class, $rejected);
        } else {
            self::assertNull($rejected);
            self::assertSame('value', $cache->get('invalid{key}'));
        }
    }

    public function testNullDriverStoresNothing(): void
    {
        $cache = $this->container(['driver' => 'null'])->get(CacheInterface::class);
        $cache->set('key', 'value');
        self::assertNull($cache->get('key'));
    }

    public function testCustomPathAndNamespace(): void
    {
        $cache = $this->container(['path' => $this->root . '/elsewhere', 'namespace' => 'blog'])->get(CacheInterface::class);
        $cache->set('key', 'value');
        self::assertDirectoryExists($this->root . '/elsewhere/blog');
        self::assertDirectoryDoesNotExist($this->root . '/var');
    }

    public function testServiceDriverUsesAnApplicationPool(): void
    {
        $container = $this->container(['driver' => 'service', 'service' => 'my.pool']);
        $pool = new ArrayAdapter();
        $container->instance('my.pool', $pool);
        $container->get(CacheInterface::class)->set('key', 'value');

        self::assertSame($pool, $container->get(CacheItemPoolInterface::class));
        self::assertTrue($pool->hasItem('key'));
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'unknown driver' => [['driver' => 'redis'], 'Unknown cache.driver "redis"'];
        yield 'negative ttl' => [['default_ttl' => -1], 'cache.default_ttl'];
        yield 'string ttl' => [['default_ttl' => '60'], 'cache.default_ttl'];
        yield 'bad namespace' => [['namespace' => 'a/b'], 'cache.namespace'];
        yield 'empty path' => [['path' => ''], 'cache.path'];
        yield 'service without id' => [['driver' => 'service'], 'cache.service'];
        yield 'service that is not a pool' => [['driver' => 'service', 'service' => Configuration::class], 'must be a PSR-6'];
    }

    /** @param array<string, mixed> $cache */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationFailsClearlyOnFirstUse(array $cache, string $message): void
    {
        $container = $this->container($cache);
        try {
            $container->get(CacheInterface::class);
            self::fail('Invalid configuration must be rejected.');
        } catch (\Throwable $exception) {
            // The container may wrap the factory's exception; the cause carries the message.
            $messages = '';
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                $messages .= $current->getMessage() . "\n";
                $cause = $current;
            }
            self::assertInstanceOf(InvalidArgumentException::class, $cause);
            self::assertStringContainsString($message, $messages);
        }
    }

    public function testCommandIsRegisteredOnlyWhenTheConsoleIsPresent(): void
    {
        $this->container();
        $this->addToAssertionCount(1); // booting without a console does not fail

        $container = new ServiceContainer();
        $container->instance(Configuration::class, new Configuration());
        $commands = new CommandRegistry();
        $container->instance(CommandRegistry::class, $commands);
        $registry = new CacheServiceRegistry($container);
        $registry->register();
        $registry->boot();
        self::assertSame(CacheClearCommand::class, $commands->all()['cache:clear']['service']);
    }

    public function testCacheClearRemovesItemsThroughTheConsoleKernel(): void
    {
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export([
            'registry' => [ConsoleServiceRegistry::class, CacheServiceRegistry::class],
        ], true) . ';');
        $application = new Application($this->root);
        $kernel = $application->getConsoleKernel();
        $cache = $application->get(CacheInterface::class);
        $cache->set('report', 'expensive');

        $output = new BufferedOutput();
        $input = new ArrayInput(['command' => 'cache:clear']);
        $input->setInteractive(false);
        self::assertSame(0, $kernel->run($input, $output));
        self::assertSame("Cleared the file cache in var/cache/app.\n", $output->fetch());
        self::assertFalse($cache->has('report'));
    }

    public function testCacheClearReportsFailure(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('clear')->willReturn(false);
        $config = new Configuration();
        $config->set('cache.driver', 'service');
        $config->set('cache.service', 'redis.pool');
        $tester = new CommandTester(new CacheClearCommand($pool, $config));

        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Could not clear the cache service redis.pool.', preg_replace('/\s+/', ' ', $tester->getErrorOutput()));
    }
}
