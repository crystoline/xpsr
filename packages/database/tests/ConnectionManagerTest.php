<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class ConnectionManagerTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testConnectionsAreCreatedOncePerNameAndConnectLazily(): void
    {
        $manager = $this->manager([
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'other' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ],
        ]);
        $main = $manager->connection();
        self::assertSame($main, $manager->connection('main'));
        self::assertNotSame($main, $manager->connection('other'));
        self::assertFalse($main->isConnected(), 'Nothing connects until a query runs.');
        self::assertSame(2, (int) $main->fetchOne('SELECT 1 + 1'));
        self::assertSame(['main', 'other'], $manager->names());
        self::assertSame('main', $manager->defaultName());
    }

    public function testOpenGivesANewConnectionWithTheSameSettings(): void
    {
        $manager = $this->manager([
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite'], 'other' => ['driver' => 'pdo_sqlite', 'path' => 'var/other.sqlite']],
        ]);
        $shared = $manager->connection();
        $own = $manager->open();
        self::assertNotSame($shared, $own, 'Not shared, so not in the shared connection\'s transactions.');
        self::assertNotSame($own, $manager->open());
        self::assertSame($shared->getParams(), $own->getParams());
        self::assertSame($manager->parameters('other'), $manager->open('other')->getParams());

        $shared->executeStatement('CREATE TABLE notes (body TEXT)');
        $shared->beginTransaction();
        $shared->insert('notes', ['body' => 'rolled back']);
        $shared->rollBack();
        $own->insert('notes', ['body' => 'kept']);
        self::assertSame(['kept'], $shared->fetchFirstColumn('SELECT body FROM notes'), 'Both reach the same database.');
    }

    public function testRelativeSqlitePathsAreResolvedAgainstTheRootAndTheirDirectoryIsCreated(): void
    {
        $manager = $this->manager([
            'default' => 'file',
            'connections' => [
                'file' => ['driver' => 'pdo_sqlite', 'path' => 'var/data/app.sqlite'],
                'url' => ['url' => 'sqlite:///var/from-url.sqlite'],
                'absolute' => ['driver' => 'pdo_sqlite', 'path' => '/tmp/absolute.sqlite'],
            ],
        ]);
        self::assertSame($this->workspace->root . '/var/data/app.sqlite', $manager->parameters('file')['path']);
        self::assertSame($this->workspace->root . '/var/from-url.sqlite', $manager->parameters('url')['path']);
        self::assertSame('pdo_sqlite', $manager->parameters('url')['driver']);
        self::assertSame('/tmp/absolute.sqlite', $manager->parameters('absolute')['path']);

        $manager->connection()->executeStatement('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
        self::assertFileExists($this->workspace->root . '/var/data/app.sqlite');
    }

    public function testServerUrlsAreParsedAndOverrideSeparateSettings(): void
    {
        $manager = $this->manager(['default' => 'mysql', 'connections' => [
            'mysql' => ['url' => 'mysql://app:s%40cret@db.example.test:3307/shop?charset=utf8mb4', 'driver' => 'pdo_mysql', 'host' => 'ignored', 'dbname' => 'ignored'],
            'pgsql' => ['url' => 'postgresql://app@db.example.test/shop', 'charset' => 'utf8'],
            'empty url' => ['url' => '', 'driver' => 'pdo_pgsql', 'host' => 'kept'],
        ]]);
        $mysql = $manager->parameters('mysql');
        self::assertSame(['pdo_mysql', 'db.example.test', 3307, 'shop', 'app', 's@cret', 'utf8mb4'], [
            $mysql['driver'], $mysql['host'], $mysql['port'], $mysql['dbname'], $mysql['user'], $mysql['password'], $mysql['charset'],
        ]);
        self::assertSame('pdo_pgsql', $manager->parameters('pgsql')['driver']);
        self::assertSame('utf8', $manager->parameters('pgsql')['charset']);
        self::assertSame('kept', $manager->parameters('empty url')['host'], 'An empty url, such as an unset DATABASE_URL, is ignored.');
        self::assertArrayNotHasKey('url', $manager->parameters('empty url'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'no default' => [['connections' => []], 'database.default must name one of database.connections.'];
        yield 'unknown connection' => [['default' => 'missing', 'connections' => ['a' => [], 'b' => []]], 'Unknown database connection "missing". Configured connections: a, b.'];
        yield 'no connections' => [['default' => 'missing'], 'Configured connections: none.'];
        yield 'connections not an array' => [['default' => 'a', 'connections' => 'sqlite'], 'database.connections must map connection names to connection parameters.'];
        yield 'parameters not an array' => [['default' => 'a', 'connections' => ['a' => 'sqlite']], 'database.connections.a must be an array of connection parameters.'];
        yield 'no driver' => [['default' => 'a', 'connections' => ['a' => ['host' => 'localhost']]], 'database.connections.a needs a "driver"'];
        yield 'unknown url scheme' => [['default' => 'a', 'connections' => ['a' => ['url' => 'oracle://db/app']]], 'database.connections.a.url must start with mysql://'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationNamesTheSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->manager($config)->connection();
    }

    public function testQueriesAreLoggedWhenEnabled(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = $level . ': ' . strtr((string) $message, array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', array_combine(
                    array_map(static fn(string $key): string => '{' . $key . '}', array_keys($context)),
                    $context,
                )));
            }
        };
        $config = ['default' => 'a', 'connections' => ['a' => ['driver' => 'pdo_sqlite', 'memory' => true]]];
        (new ConnectionManager($config, $this->workspace->root, $logger))->connection()->fetchOne('SELECT 42');
        self::assertSame([], array_filter($logger->messages, static fn(string $message): bool => str_contains($message, 'SELECT 42')), 'Queries are not logged by default.');

        (new ConnectionManager([...$config, 'log_queries' => true], $this->workspace->root, $logger))->connection()->fetchOne('SELECT 42');
        self::assertContains('debug: Executing query: SELECT 42', $logger->messages);
    }

    /** @param array<string, mixed> $config */
    private function manager(array $config): ConnectionManager
    {
        return new ConnectionManager($config, $this->workspace->root);
    }
}
