<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Database\Migration\MigratorInterface;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Database\Testing\DatabaseTransactions;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    private Workspace $workspace;
    private Application $app;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        $this->app = new Application($this->workspace->root);
        $this->app->getConfig()->set('app.registry', [DatabaseServiceRegistry::class]);
        $this->app->getConfig()->set('database', [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'pdo_sqlite', 'path' => 'var/main.sqlite'],
                'other' => ['driver' => 'pdo_sqlite', 'path' => 'var/other.sqlite'],
            ],
        ]);
        $this->app->boot();
        foreach (['main', 'other'] as $name) {
            $this->connection($name)->executeStatement('CREATE TABLE notes (body TEXT)');
        }
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() before #[After] hooks, so roll back here before closing.
        $this->rollBackDatabaseTransactions();
        foreach (['main', 'other'] as $name) {
            $this->connection($name)->close();
        }
        $this->workspace->remove();
    }

    public function testTheRollbackRunsAfterEveryTest(): void
    {
        $attributes = (new \ReflectionMethod($this, 'rollBackDatabaseTransactions'))->getAttributes(\PHPUnit\Framework\Attributes\After::class);
        self::assertCount(1, $attributes);
    }

    private function assertNothingKept(): void
    {
        $this->rollBackDatabaseTransactions();
        foreach (['main', 'other'] as $name) {
            self::assertFalse($this->connection($name)->isTransactionActive());
            self::assertSame(0, (int) $this->connection($name)->fetchOne('SELECT COUNT(*) FROM notes'), "{$name} kept a row.");
        }
    }

    protected function application(): Application
    {
        return $this->app;
    }

    public function testWritesToTheDefaultConnectionAreRolledBackIncludingNestedTransactions(): void
    {
        $this->beginDatabaseTransaction();
        $db = $this->app->get(Connection::class);
        $db->insert('notes', ['body' => 'kept only during the test']);
        $db->transactional(static function (Connection $db): void {
            $db->insert('notes', ['body' => 'nested']);
        });
        $db->beginTransaction(); // left open by the code under test
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM notes'));
        $this->assertNothingKept();
    }

    public function testNamedConnectionsAreRolledBackToo(): void
    {
        $this->beginDatabaseTransaction('main', 'other');
        $this->connection('main')->insert('notes', ['body' => 'a']);
        $this->connection('other')->insert('notes', ['body' => 'b']);
        self::assertTrue($this->connection('other')->isTransactionActive());
        $this->assertNothingKept();
    }

    public function testTheMigrationRunnerIsConfigurable(): void
    {
        self::assertInstanceOf(MigratorFactory::class, $this->app->get(MigratorFactoryInterface::class), 'XPSR runner by default.');

        $custom = new class implements MigratorFactoryInterface {
            public function create(?string $connection = null): MigratorInterface
            {
                throw new \LogicException('not used');
            }
        };
        $this->app->getContainer()->instance('custom.runner', $custom);
        $this->app->getContainer()->factory(MigratorFactoryInterface::class, static fn() => null);
        $registry = new DatabaseServiceRegistry($this->app->getContainer());
        $this->app->getConfig()->set('database.migrations.runner', 'custom.runner');
        $registry->register();
        self::assertSame($custom, $this->app->get(MigratorFactoryInterface::class));

        $this->app->getConfig()->set('database.migrations.runner', ConnectionManager::class);
        $registry->register();
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('database.migrations.runner must name a service implementing');
        $this->app->get(MigratorFactoryInterface::class);
    }

    private function connection(string $name): Connection
    {
        return $this->app->get(ConnectionManager::class)->connection($name);
    }
}
