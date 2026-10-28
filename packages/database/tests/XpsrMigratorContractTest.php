<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\Migrator;
use Crystoline\Xpsr\Database\Migration\MigratorInterface;
use Crystoline\Xpsr\Database\Testing\MigratorContractTestCase;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Doctrine\DBAL\Connection;

/** XPSR's own migrator passes the contract that alternative runners must pass. */
final class XpsrMigratorContractTest extends MigratorContractTestCase
{
    private Workspace $workspace;
    private Connection $connection;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        $this->connection = (new ConnectionManager(
            ['default' => 'test', 'connections' => ['test' => ['driver' => 'pdo_sqlite', 'path' => 'var/contract.sqlite']]],
            $this->workspace->root,
        ))->connection();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->workspace->remove();
    }

    protected function migrator(): MigratorInterface
    {
        return new Migrator($this->connection, $this->workspace->migrations);
    }

    protected function addMigration(string $table): string
    {
        return $this->write("create_{$table}", "\$db->executeStatement('CREATE TABLE {$table} (id INTEGER PRIMARY KEY)');", "\$db->executeStatement('DROP TABLE {$table}');");
    }

    protected function addFailingMigration(): string
    {
        return $this->write('failing', "throw new RuntimeException('This migration fails.');");
    }

    protected function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tableExists($table);
    }

    private function write(string $suffix, string $up, string $down = ''): string
    {
        $name = sprintf('2026010100%04d_%s', ++$this->sequence, $suffix);
        $this->workspace->migration($name, $up, $down);

        return $name;
    }
}
