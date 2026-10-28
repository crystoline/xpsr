<?php

declare(strict_types=1);

namespace Crystoline\Database\Tests;

use Crystoline\Database\ConnectionManager;
use Crystoline\Database\Migration\MigrationException;
use Crystoline\Database\Migration\Migrator;
use Crystoline\Database\Tests\Fixtures\Workspace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite, and against MySQL and PostgreSQL when XPSR_TEST_MYSQL_URL or
 * XPSR_TEST_PGSQL_URL name a database the tests may create tables in, for example
 * mysql://root:secret@127.0.0.1/xpsr_test.
 */
final class MigratorTest extends TestCase
{
    private Workspace $workspace;
    private ?Connection $connection = null;
    /** A suffix for every table, so runs against a shared server do not collide. */
    private string $suffix;

    /** @return iterable<string, array{string}> */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        foreach (['mysql' => 'XPSR_TEST_MYSQL_URL', 'pgsql' => 'XPSR_TEST_PGSQL_URL'] as $name => $variable) {
            if (is_string(getenv($variable)) && getenv($variable) !== '') {
                yield $name => [$name];
            }
        }
    }

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        $this->suffix = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null && $this->connection->getParams()['driver'] !== 'pdo_sqlite') {
            $schema = $this->connection->createSchemaManager();
            foreach (['users', 'posts', 'broken', 'migrations'] as $table) {
                if ($schema->tableExists("{$table}_{$this->suffix}")) {
                    $schema->dropTable("{$table}_{$this->suffix}");
                }
            }
        }
        $this->connection?->close();
        $this->workspace->remove();
    }

    #[DataProvider('databases')]
    public function testMigrateRunsPendingFilesInOrderAsOneBatch(string $database): void
    {
        $migrator = $this->migrator($database);
        $this->workspace->migration('20260102000000_create_posts', $this->create('posts'), $this->drop('posts'));
        $this->workspace->migration('20260101000000_create_users', $this->create('users'), $this->drop('users'));
        self::assertSame(['20260101000000_create_users', '20260102000000_create_posts'], $migrator->pending());

        $order = [];
        $ran = $migrator->migrate(static function (string $name) use (&$order): void {
            $order[] = $name;
        });

        self::assertSame(['20260101000000_create_users', '20260102000000_create_posts'], $ran);
        self::assertSame($ran, $order);
        self::assertTrue($this->tableExists('users'));
        self::assertTrue($this->tableExists('posts'));
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate(), 'Nothing runs twice.');
        self::assertSame([
            ['migration' => '20260101000000_create_users', 'status' => 'ran', 'batch' => 1],
            ['migration' => '20260102000000_create_posts', 'status' => 'ran', 'batch' => 1],
        ], $migrator->status());
    }

    #[DataProvider('databases')]
    public function testRollbackUndoesTheLastBatchOrANumberOfMigrations(string $database): void
    {
        $migrator = $this->migrator($database);
        $this->workspace->migration('20260101000000_create_users', $this->create('users'), $this->drop('users'));
        $migrator->migrate();
        $this->workspace->migration('20260102000000_create_posts', $this->create('posts'), $this->drop('posts'));
        $this->workspace->migration('20260103000000_create_broken', $this->create('broken'), $this->drop('broken'));
        $migrator->migrate();
        self::assertSame([1, 2, 2], array_column($migrator->status(), 'batch'));

        self::assertSame(['20260103000000_create_broken', '20260102000000_create_posts'], $migrator->rollback());
        self::assertFalse($this->tableExists('posts'));
        self::assertTrue($this->tableExists('users'));
        self::assertSame(['ran', 'pending', 'pending'], array_column($migrator->status(), 'status'));

        $migrator->migrate();
        self::assertSame(['20260103000000_create_broken'], $migrator->rollback(steps: 1));
        self::assertSame(['20260102000000_create_posts', '20260101000000_create_users'], $migrator->rollback(steps: 5));
        self::assertSame([], $migrator->rollback(), 'Nothing left to roll back.');
        self::assertFalse($this->tableExists('users'));
    }

    #[DataProvider('databases')]
    public function testAFailedMigrationIsNotRecordedAndStopsTheRun(string $database): void
    {
        $migrator = $this->migrator($database);
        $this->workspace->migration('20260101000000_create_users', $this->create('users'), $this->drop('users'));
        $this->workspace->migration('20260102000000_broken', $this->create('broken') . "\n        \$db->executeStatement('SELECT * FROM missing_table_{$this->suffix}');");
        $this->workspace->migration('20260103000000_create_posts', $this->create('posts'), $this->drop('posts'));

        try {
            $migrator->migrate();
            self::fail('The broken migration did not fail.');
        } catch (MigrationException $exception) {
            self::assertStringStartsWith('Migration 20260102000000_broken failed: ', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
        self::assertSame(['ran', 'pending', 'pending'], array_column($migrator->status(), 'status'));
        self::assertTrue($this->tableExists('users'));
        self::assertFalse($this->tableExists('posts'), 'Later migrations do not run.');
        $mysql = $this->connection?->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        self::assertSame($mysql, $this->tableExists('broken'), 'With transactional DDL the failed migration leaves nothing behind; MySQL commits each change.');
    }

    public function testNonTransactionalMigrationsKeepCompletedStatements(): void
    {
        $migrator = $this->migrator('sqlite');
        $this->workspace->migration(
            '20260101000000_partial',
            $this->create('broken') . "\n        throw new RuntimeException('stop');",
            extra: 'protected bool $transactional = false;',
        );
        $this->expectException(MigrationException::class);
        try {
            $migrator->migrate();
        } finally {
            self::assertTrue($this->tableExists('broken'));
            self::assertSame(['pending'], array_column($migrator->status(), 'status'));
        }
    }

    public function testRollingBackAMigrationWithoutDownFails(): void
    {
        $migrator = $this->migrator('sqlite');
        $this->workspace->migration('20260101000000_create_users', $this->create('users'));
        $migrator->migrate();
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Rollback of 20260101000000_create_users failed: This migration cannot be rolled back: it has no down() method.');
        $migrator->rollback();
    }

    public function testMigrationsWhoseFileWasDeletedAreReportedAndCannotBeRolledBack(): void
    {
        $migrator = $this->migrator('sqlite');
        $file = $this->workspace->migration('20260101000000_create_users', $this->create('users'), $this->drop('users'));
        $migrator->migrate();
        unlink($file);
        self::assertSame([['migration' => '20260101000000_create_users', 'status' => 'missing', 'batch' => 1]], $migrator->status());
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Cannot roll back 20260101000000_create_users: it has run, but');
        $migrator->rollback();
    }

    public function testBadlyNamedFilesAndFilesThatReturnNoMigrationAreRejected(): void
    {
        $migrator = $this->migrator('sqlite');
        file_put_contents($this->workspace->migrations . '/CreateUsers.php', '<?php return null;');
        try {
            $migrator->pending();
            self::fail('A badly named file was accepted.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('Migration file CreateUsers.php is not named like 20260131120000_create_users_table.php.', $exception->getMessage());
        }
        unlink($this->workspace->migrations . '/CreateUsers.php');
        file_put_contents($this->workspace->migrations . '/20260101000000_nothing.php', '<?php return 42;');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Migration 20260101000000_nothing must return an instance of Crystoline\Database\Migration\Migration');
        $migrator->migrate();
    }

    public function testAMissingDirectoryMeansNoMigrations(): void
    {
        $migrator = new Migrator($this->connection('sqlite'), $this->workspace->root . '/missing');
        self::assertSame([], $migrator->status());
        self::assertSame([], $migrator->migrate());
        self::assertSame([], $migrator->rollback());
    }

    public function testRollbackNeedsAPositiveNumberOfSteps(): void
    {
        $this->expectException(MigrationException::class);
        $this->migrator('sqlite')->rollback(steps: 0);
    }

    private function migrator(string $database): Migrator
    {
        return new Migrator($this->connection($database), $this->workspace->migrations, "migrations_{$this->suffix}");
    }

    private function connection(string $database): Connection
    {
        $config = [
            'default' => $database,
            'connections' => [
                'sqlite' => ['driver' => 'pdo_sqlite', 'path' => 'var/test.sqlite'],
                'mysql' => ['url' => (string) getenv('XPSR_TEST_MYSQL_URL')],
                'pgsql' => ['url' => (string) getenv('XPSR_TEST_PGSQL_URL')],
            ],
        ];

        return $this->connection = (new ConnectionManager($config, $this->workspace->root))->connection();
    }

    private function create(string $table): string
    {
        return "\$db->executeStatement('CREATE TABLE {$table}_{$this->suffix} (id INTEGER NOT NULL, PRIMARY KEY (id))');";
    }

    private function drop(string $table): string
    {
        return "\$db->executeStatement('DROP TABLE {$table}_{$this->suffix}');";
    }

    private function tableExists(string $table): bool
    {
        return $this->connection?->createSchemaManager()->tableExists("{$table}_{$this->suffix}") ?? false;
    }
}
