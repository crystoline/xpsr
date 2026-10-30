<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Migration;

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
    private bool $checked = false;

    public function __construct(private readonly Connection $connection, private readonly string $table = 'migrations')
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $table)) {
            throw new InvalidArgumentException('database.migrations.table must be a plain table name: letters, digits, and underscores.');
        }
    }

    /** @throws MigrationException when the table exists but is not XPSR's, such as Laravel's */
    public function exists(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tableExists($this->table)) {
            return false;
        }
        if (!$this->checked) {
            $columns = array_map(
                static fn(Column $column): string => strtolower($column->getObjectName()->getIdentifier()->getValue()),
                $schema->introspectTableByUnquotedName($this->table)->getColumns(),
            );
            if (!in_array('executed_at', $columns, true)) {
                // Laravel's has id, migration, and batch: a database moving from Laravel has one.
                $laravel = array_diff(['id', 'migration', 'batch'], $columns) === [];
                throw new MigrationException(sprintf(
                    'The table %s is not XPSR\'s record of migrations: it has no executed_at column%s. Set database.migrations.table to another name, such as xpsr_migrations, so XPSR keeps its own record.',
                    $this->table,
                    $laravel ? '. It looks like Laravel\'s' : '',
                ));
            }
            $this->checked = true;
        }

        return true;
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
