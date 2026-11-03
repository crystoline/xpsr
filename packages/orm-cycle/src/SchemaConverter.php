<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Cycle\Database\Schema\AbstractColumn;
use Cycle\Database\Schema\AbstractTable;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Stringable;

/**
 * Translates the tables Cycle declares for the entities into a DBAL schema, so that
 * make:migration --diff can compare the database with them.
 *
 * @internal
 */
final class SchemaConverter
{
    /** @param list<AbstractTable> $tables */
    public static function toDbal(array $tables): Schema
    {
        $editor = Schema::editor();
        foreach ($tables as $table) {
            $editor->addTable(self::table($table));
        }

        return $editor->create();
    }

    private static function table(AbstractTable $table): Table
    {
        $name = $table->getFullName();
        $editor = Table::editor()->setUnquotedName($name);
        $primary = $table->getPrimaryKeys();
        foreach ($table->getColumns() as $column) {
            $editor->addColumn(self::column($name, $column, in_array($column->getName(), $primary, true)));
        }
        if ($primary !== []) {
            $editor->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$primary)->create());
        }
        foreach ($table->getIndexes() as $index) {
            $editor->addIndex(
                Index::editor()
                    ->setUnquotedName($index->getName())
                    ->setType($index->isUnique() ? IndexType::UNIQUE : IndexType::REGULAR)
                    ->setUnquotedColumnNames(...$index->getColumns())
                    ->create(),
            );
        }
        foreach ($table->getForeignKeys() as $key) {
            $editor->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName($key->getName())
                    ->setUnquotedReferencingColumnNames(...$key->getColumns())
                    ->setUnquotedReferencedTableName($key->getForeignTable())
                    ->setUnquotedReferencedColumnNames(...$key->getForeignKeys())
                    ->setOnDeleteAction(self::action($key->getDeleteRule()))
                    ->setOnUpdateAction(self::action($key->getUpdateRule()))
                    ->create(),
            );
        }

        return $editor->create();
    }

    private static function column(string $table, AbstractColumn $column, bool $primary): Column
    {
        $abstract = $column->getAbstractType();
        [$type, $length, $autoincrement] = match ($abstract) {
            'primary' => [Types::INTEGER, null, true],
            'smallPrimary' => [Types::SMALLINT, null, true],
            'bigPrimary' => [Types::BIGINT, null, true],
            'boolean', 'bit' => [Types::BOOLEAN, null, false],
            'integer' => [Types::INTEGER, null, false],
            'tinyInteger', 'smallInteger' => [Types::SMALLINT, null, false],
            'bigInteger', 'snowflake' => [Types::BIGINT, null, false],
            'string' => [Types::STRING, $column->getSize() > 0 ? $column->getSize() : 255, false],
            'enum' => [Types::STRING, max([...array_map(strlen(...), $column->getEnumValues()), 1]), false],
            'ulid' => [Types::STRING, 26, false],
            'uuid' => [Types::GUID, null, false],
            'text', 'tinyText', 'mediumText', 'longText' => [Types::TEXT, null, false],
            'float', 'double' => [Types::FLOAT, null, false],
            'decimal' => [Types::DECIMAL, null, false],
            'datetime', 'timestamp', 'datetime2' => [Types::DATETIME_MUTABLE, null, false],
            'date' => [Types::DATE_MUTABLE, null, false],
            'time' => [Types::TIME_MUTABLE, null, false],
            'timestamptz', 'timetz' => [Types::DATETIMETZ_MUTABLE, null, false],
            'binary', 'tinyBinary', 'longBinary' => [Types::BLOB, null, false],
            'json', 'jsonb' => [Types::JSON, null, false],
            default => throw new InvalidArgumentException(sprintf('make:migration --diff cannot describe the column %s.%s of type %s; write that part of the migration by hand.', $table, $column->getName(), $abstract)),
        };
        $editor = Column::editor()
            ->setUnquotedName($column->getName())
            ->setTypeName($type)
            ->setNotNull($primary || !$column->isNullable())
            ->setAutoincrement($autoincrement);
        if ($length !== null) {
            $editor->setLength($length);
        }
        if ($abstract === 'decimal') {
            $editor->setPrecision($column->getPrecision())->setScale($column->getScale());
        }
        if (!$autoincrement && $column->hasDefaultValue()) {
            $default = $column->getDefaultValue();
            if (!is_scalar($default) && !$default instanceof Stringable) {
                throw new InvalidArgumentException(sprintf('make:migration --diff cannot describe the default value of the column %s.%s; write that part of the migration by hand.', $table, $column->getName()));
            }
            $editor->setDefaultValue(is_scalar($default) ? $default : (string) $default);
        }

        return $editor->create();
    }

    private static function action(string $rule): ReferentialAction
    {
        return match (strtoupper($rule)) {
            'CASCADE' => ReferentialAction::CASCADE,
            'SET NULL' => ReferentialAction::SET_NULL,
            'SET DEFAULT' => ReferentialAction::SET_DEFAULT,
            'RESTRICT' => ReferentialAction::RESTRICT,
            default => ReferentialAction::NO_ACTION,
        };
    }
}
