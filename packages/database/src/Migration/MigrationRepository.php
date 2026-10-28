<?php

declare(strict_types=1);

namespace Crystoline\Database\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;

/**
 * The table that records which migrations have run, and in which batch.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MigrationRepository
{
    public function __construct(private readonly Connection $connection, private readonly string $table = 'migrations')
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $table)) {
            throw new InvalidArgumentException('database.migrations.table must be a plain table name: letters, digits, and underscores.');
        }
    }

    public function exists(): bool
    {
        return $this->connection->createSchemaManager()->tableExists($this->table);
    }

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }
        $this->connection->createSchemaManager()->createTable(
            Table::editor()
                ->setUnquotedName($this->table)
                ->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                    Column::editor()->setUnquotedName('migration')->setTypeName(Types::STRING)->setLength(255)->create(),
                    Column::editor()->setUnquotedName('batch')->setTypeName(Types::INTEGER)->create(),
                    Column::editor()->setUnquotedName('executed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                )
                ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->setUniqueConstraints(UniqueConstraint::editor()->setUnquotedName($this->table . '_migration_unique')->setUnquotedColumnNames('migration')->create())
                ->create(),
        );
    }

    /** @return array<string, int> migration name => batch, in the order they ran */
    public function ran(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $ran = [];
        foreach ($this->connection->createQueryBuilder()->select('migration', 'batch')->from($this->table)->orderBy('id')->fetchAllAssociative() as $row) {
            $ran[(string) $row['migration']] = (int) $row['batch'];
        }

        return $ran;
    }

    public function lastBatch(): int
    {
        $ran = $this->ran();

        return $ran === [] ? 0 : max($ran);
    }

    public function log(string $migration, int $batch): void
    {
        $this->connection->insert($this->table, [
            'migration' => $migration,
            'batch' => $batch,
            'executed_at' => new DateTimeImmutable(),
        ], ['executed_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function delete(string $migration): void
    {
        $this->connection->delete($this->table, ['migration' => $migration]);
    }
}
