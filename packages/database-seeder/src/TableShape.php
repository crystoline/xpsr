<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

/**
 * What seeding needs to know about a table: its columns with their types, its primary key, and
 * how to write its names in SQL. When the table has a column type DBAL does not know, it cannot
 * be read; the table is then seeded without type information.
 *
 * @internal
 */
final class TableShape
{
    /**
     * @param array<string, Type>|null $types by column name; null when the table could not be read
     * @param array<string, string> $quoted column name => the name in SQL
     * @param list<string> $primaryKey
     * @param list<string> $autoIncrement
     */
    private function __construct(
        public readonly string $table,
        public readonly string $sql,
        public readonly ?array $types,
        private readonly array $quoted,
        public readonly array $primaryKey,
        public readonly array $autoIncrement,
        public readonly AbstractPlatform $platform,
    ) {}

    public static function of(Connection $db, string $table): self
    {
        if ($table === '') {
            throw new InvalidArgumentException('A table to seed needs a name.');
        }
        $platform = $db->getDatabasePlatform();
        $schema = $db->createSchemaManager();
        [$namespace, $name] = str_contains($table, '.') ? explode('.', $table, 2) : [null, $table];
        if ($name === '' || $namespace === '') {
            throw new InvalidArgumentException("\"{$table}\" is not a table name.");
        }
        $definition = $failure = null;
        try {
            try {
                $definition = $schema->introspectTableByUnquotedName($name, $namespace);
            } catch (TableDoesNotExist) {
                // A name written with capitals on PostgreSQL is found as it is written.
                $definition = $schema->introspectTableByQuotedName($name, $namespace);
            }
        } catch (DbalException $exception) {
            $failure = $exception;
        }
        $columns = $definition?->getColumns() ?? [];
        if ($definition === null || $columns === []) {
            if (!$schema->tableExists($table)) {
                throw new InvalidArgumentException("There is no table \"{$table}\" to seed. Run the migrations first: php xpsr migrate.", previous: $failure);
            }

            // A column type DBAL does not know: names are quoted as given, and values go by their PHP type.
            return new self($table, implode('.', array_map($platform->quoteSingleIdentifier(...), explode('.', $table))), null, [], [], [], $platform);
        }
        $types = $quoted = $autoIncrement = [];
        foreach ($columns as $column) {
            $columnName = $column->getObjectName()->getIdentifier()->getValue();
            $types[$columnName] = Type::getType($column->getTypeName());
            $quoted[$columnName] = $column->getObjectName()->toSQL($platform);
            if ($column->getAutoincrement()) {
                $autoIncrement[] = $columnName;
            }
        }
        $primaryKey = array_map(
            static fn(UnqualifiedName $column): string => $column->getIdentifier()->getValue(),
            $definition->getPrimaryKeyConstraint()?->getColumnNames() ?? [],
        );

        return new self($table, $definition->getObjectName()->toSQL($platform), $types, $quoted, $primaryKey, $autoIncrement, $platform);
    }

    /** The column's name in SQL. An unknown column is refused, with the columns the table has. */
    public function column(string $name): string
    {
        if ($this->types === null) {
            return $this->platform->quoteSingleIdentifier($name);
        }

        return $this->quoted[$name] ?? throw new InvalidArgumentException(
            "The table {$this->table} has no column \"{$name}\". Its columns: " . implode(', ', array_keys($this->quoted)) . '.',
        );
    }

    public function type(string $column): ?Type
    {
        return $this->types[$column] ?? null;
    }

    /** @return list<string> the column names; none when the table could not be read */
    public function columns(): array
    {
        return array_keys($this->quoted);
    }
}
