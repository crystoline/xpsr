<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Lock\Console\MakeLockTableCommand;
use Crystoline\Xpsr\Lock\LockNotAcquiredException;
use Crystoline\Xpsr\Lock\LockOptions;
use Crystoline\Xpsr\Lock\Locks;
use Crystoline\Xpsr\Lock\LockServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The stores lock.store names. Redis and Redlock also run against real servers when
 * XPSR_TEST_REDIS_URLS lists three, comma-separated; the database store also runs against MySQL
 * or PostgreSQL when XPSR_TEST_MYSQL_URL or XPSR_TEST_PGSQL_URL is set.
 */
final class LockServiceRegistryTest extends TestCase
{
    /** Nothing listens on port 1, so a server there is down. */
    private const DOWN = 'redis://127.0.0.1:1';

    private string $root;
    /** A suffix for keys and tables, so runs against shared servers do not collide. */
    private string $suffix;
    private ?Application $application = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr lock ' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        $this->suffix = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if ($this->application !== null && $this->application->getConfig()->get('database') !== null) {
            $this->application->get(MigratorFactory::class)->create()->rollback();
            $this->application->get(ConnectionManager::class)->connection()->createSchemaManager()->dropTable("migrations_{$this->suffix}");
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string, mixed> $lock */
    private function application(array $lock = [], ?string $databaseUrl = null): Application
    {
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $registry = [ConsoleServiceRegistry::class, LockServiceRegistry::class];
        if ($databaseUrl !== null) {
            $registry = [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, LockServiceRegistry::class];
            $config->set('database', [
                'default' => 'test',
                'connections' => ['test' => $databaseUrl === 'sqlite' ? ['driver' => 'pdo_sqlite', 'path' => 'var/locks.sqlite'] : ['url' => $databaseUrl]],
                'migrations' => ['path' => 'migrations', 'table' => "migrations_{$this->suffix}"],
            ]);
        }
        $config->set('app.registry', $registry);
        $config->set('lock', $lock);
        $this->application->boot();

        return $this->application;
    }

    public function testTheDefaultStoreKeepsFilesInVarLocks(): void
    {
        $application = $this->application();
        self::assertInstanceOf(FlockStore::class, $application->get(PersistingStoreInterface::class));
        self::assertSame($application->get(LockFactory::class), $application->get(LockFactory::class), 'Shared.');
        $locks = $application->get(Locks::class);

        $held = $locks->lock('nightly-report');
        self::assertTrue($held->acquire());
        self::assertNotSame([], glob($this->root . '/var/locks/*.lock'));
        $this->expectException(LockNotAcquiredException::class);
        $locks->run('nightly-report', static fn(): string => 'ran twice');
    }

    public function testOtherStoresComeFromSymfonyLockDsns(): void
    {
        self::assertInstanceOf(InMemoryStore::class, $this->application(['store' => 'in-memory'])->get(PersistingStoreInterface::class));
        $failure = $this->storeFailure(['store' => 'carrier-pigeon://loft']);
        self::assertInstanceOf(InvalidArgumentException::class, $failure);
        self::assertSame('lock.store: Unsupported Connection: "carrier-pigeon://loft".', $failure->getMessage());
    }

    /**
     * Why the store could not be built; the container wraps it.
     *
     * @param array<string, mixed> $lock
     */
    private function storeFailure(array $lock): ?\Throwable
    {
        try {
            $this->application($lock)->get(PersistingStoreInterface::class);
        } catch (ContainerException $exception) {
            return $exception->getPrevious();
        }
        self::fail('The store was built.');
    }

    public function testRedlockCombinesTheServersAndNeedsAMajority(): void
    {
        $store = $this->application(['store' => 'redlock', 'redlock' => ['redis://redis-1:6379', 'redis://redis-2:6379', 'redis://redis-3:6379']])
            ->get(PersistingStoreInterface::class);
        self::assertInstanceOf(CombinedStore::class, $store, 'Connections are lazy, so no server is needed until a lock is taken.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redlock store needs three or more independent Redis servers in lock.redlock');
        LockOptions::fromArray(['store' => 'redlock', 'redlock' => ['redis://redis-1:6379', 'redis://redis-2:6379']]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['stores' => 'flock'], 'Unknown option lock.stores. Known options: store, redlock, database, prefix, ttl.'];
        yield 'empty store' => [['store' => ''], 'lock.store must be flock, database, redlock, or a Symfony Lock DSN'];
        yield 'not Redis' => [['redlock' => ['memcached://localhost']], 'lock.redlock must list Redis DSNs that start with redis:// or rediss://.'];
        yield 'table' => [['database' => ['table' => 'locks; DROP']], 'lock.database.table must be a plain table name'];
        yield 'connection' => [['database' => ['connection' => '']], 'lock.database.connection must name a database connection'];
        yield 'prefix' => [['prefix' => 'my app'], 'lock.prefix may contain only letters, digits'];
        yield 'ttl' => [['ttl' => 'forever'], 'lock.ttl must be more than zero seconds'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreExplained(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        LockOptions::fromArray($config);
    }

    public function testTheDatabaseStoreNeedsTheDatabasePackageRegistered(): void
    {
        $failure = $this->storeFailure(['store' => 'database']);
        self::assertInstanceOf(LogicException::class, $failure);
        self::assertStringStartsWith('The database lock store needs crystoline/xpsr-database, with DatabaseServiceRegistry in app.registry.', $failure->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        foreach (['mysql' => 'XPSR_TEST_MYSQL_URL', 'pgsql' => 'XPSR_TEST_PGSQL_URL'] as $name => $variable) {
            $url = getenv($variable);
            if (is_string($url) && $url !== '') {
                yield $name => [$url];
            }
        }
    }

    #[DataProvider('databases')]
    public function testTheDatabaseStoreKeepsLocksOutOfTheApplicationsTransactions(string $url): void
    {
        $table = "lock_keys_{$this->suffix}";
        $application = $this->application(['store' => 'database', 'database' => ['table' => $table]], $url);
        self::assertArrayHasKey('make:lock-table', $application->get(CommandRegistry::class)->all());
        $make = new CommandTester($application->get(MakeLockTableCommand::class));
        self::assertSame(Command::SUCCESS, $make->execute([]));
        self::assertStringContainsString("_create_{$table}_table.php", $make->getDisplay());
        self::assertCount(1, $application->get(MigratorFactory::class)->create()->migrate());
        self::assertInstanceOf(DoctrineDbalStore::class, $application->get(PersistingStoreInterface::class));

        $connections = $application->get(ConnectionManager::class);
        $db = $connections->connection();
        $observer = $connections->open();
        $locks = $application->get(Locks::class);

        $db->beginTransaction();
        $lock = $locks->lock("payment-{$this->suffix}");
        self::assertTrue($lock->acquire());
        self::assertSame(1, (int) $observer->fetchOne("SELECT COUNT(*) FROM {$table}"), 'Other processes see the lock before the transaction commits.');
        $db->rollBack();
        self::assertSame(1, (int) $observer->fetchOne("SELECT COUNT(*) FROM {$table}"), 'A rollback does not undo the lock.');
        $again = $locks->lock("payment-{$this->suffix}");
        self::assertFalse($again->acquire());

        $lock->release();
        self::assertSame(0, (int) $observer->fetchOne("SELECT COUNT(*) FROM {$table}"));
        self::assertTrue($again->acquire());
        $again->release();
        $observer->close();
    }

    /** @return iterable<string, array{array<string, mixed>, bool, bool}> lock options, whether a lock can be taken, whether locks expire */
    public static function servers(): iterable
    {
        yield 'in memory' => [['store' => 'in-memory'], true, false];
        $urls = array_values(array_filter(array_map('trim', explode(',', (string) getenv('XPSR_TEST_REDIS_URLS')))));
        if (count($urls) < 3) {
            return;
        }
        [$first, $second, $third] = $urls;
        yield 'redis' => [['store' => $first], true, true];
        yield 'redlock, all three up' => [['store' => 'redlock', 'redlock' => [$first, $second, $third]], true, true];
        yield 'redlock, one of three down' => [['store' => 'redlock', 'redlock' => [$first, self::DOWN, $third]], true, true];
        yield 'redlock, two of three down' => [['store' => 'redlock', 'redlock' => [$first, self::DOWN, 'redis://127.0.0.1:2']], false, true];
    }

    /** @param array<string, mixed> $lock */
    #[DataProvider('servers')]
    public function testOneHolderAtATime(array $lock, bool $available, bool $expires): void
    {
        $locks = $this->application($lock)->get(Locks::class);
        $key = "exclusive-{$this->suffix}";

        $first = $locks->lock($key, '10 seconds');
        self::assertSame($available, $first->acquire());
        if (!$available) {
            $this->expectException(LockNotAcquiredException::class);
            $locks->run($key, static fn(): string => 'without a majority');
        }
        $second = $locks->lock($key, '10 seconds');
        self::assertFalse($second->acquire(), 'The lock has one holder.');
        $first->refresh(20);
        if ($expires) {
            self::assertEqualsWithDelta(20.0, $first->getRemainingLifetime(), 1.0, 'The holder can extend it.');
        } else {
            self::assertNull($first->getRemainingLifetime(), 'Kept until released.');
        }
        $first->release();
        self::assertTrue($second->acquire(), 'Released, so the next one gets it.');
        $second->release();
    }
}
