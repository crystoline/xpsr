<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Console\MakeSessionTableCommand;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Database\Session\DatabaseSessionStore;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use Crystoline\Xpsr\Testing\SessionStoreContractTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The database store passes the session store contract, in a table created by the migration
 * that make:session-table writes. Runs on the server in XPSR_TEST_PGSQL_URL or
 * XPSR_TEST_MYSQL_URL when set (as in CI's database job), otherwise on SQLite.
 */
final class DatabaseSessionStoreTest extends SessionStoreContractTestCase
{
    private Workspace $workspace;
    private Connection $connection;
    private MigratorFactory $migrators;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = new Workspace();
        $this->suffix = bin2hex(random_bytes(3));
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $connections = new ConnectionManager(
            ['default' => 'test', 'connections' => ['test' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/sessions.sqlite']]],
            $this->workspace->root,
        );
        $this->connection = $connections->connection();
        $this->migrators = new MigratorFactory($connections, ['path' => 'migrations', 'table' => "migrations_{$this->suffix}"], $this->workspace->root);

        $tester = $this->command($this->table());
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression("#Created migrations/\\d{14}_create_{$this->table()}_table\\.php#", $tester->getDisplay());
        self::assertCount(1, $this->migrators->create()->migrate());
    }

    protected function tearDown(): void
    {
        $this->migrators->create()->rollback();
        $schema = $this->connection->createSchemaManager();
        self::assertFalse($schema->tableExists($this->table()), 'Rolling back drops the session table.');
        $schema->dropTable("migrations_{$this->suffix}");
        $this->connection->close();
        $this->workspace->remove();
    }

    protected function createStore(int $lifetime, MockClock $clock): SessionStoreInterface
    {
        return new DatabaseSessionStore($this->connection, $this->table(), $lifetime, $clock);
    }

    public function testTheCommandWritesOneSessionTableMigration(): void
    {
        $again = $this->command($this->table());
        self::assertSame(Command::FAILURE, $again->execute([]));
        self::assertStringContainsString('A migration for the session table already exists', $again->getDisplay());

        self::assertSame(Command::INVALID, $this->command('bad name')->execute([]));
        self::assertTrue($this->connection->createSchemaManager()->tableExists($this->table()));
    }

    private function table(): string
    {
        return "sessions_{$this->suffix}";
    }

    private function command(string $table): CommandTester
    {
        return new CommandTester(new MakeSessionTableCommand(
            $this->migrators,
            new Configuration(['session' => ['database' => ['table' => $table]]]),
        ));
    }
}
