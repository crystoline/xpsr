<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Schema;

use Closure;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\Deferrability;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\MatchType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\Types;
use ReflectionClass;

/**
 * PHP that builds tables with DBAL's schema editors, for migrate:baseline. Each table, column,
 * index, unique constraint, and foreign key is written with only the settings that differ from
 * the editors' defaults, so the code reads like a hand-written migration and runs on any
 * database DBAL supports.
 *
 * @internal
 */
final class SchemaCode
{
    /** Table options other platforms ignore, kept for MySQL and MariaDB. */
    private const TABLE_OPTIONS = ['engine', 'charset', 'collation'];

    /** The tables, as the elements of an array at the given indentation. */
    public readonly string $code;

    /** @var list<class-string> the classes the code names, for use statements */
    public readonly array $classes;

    /** @var array<class-string, true> */
    private array $used = [];

    /** @var array<string, string> type name => Types constant name */
    private readonly array $types;

    /**
     * @param list<Table> $tables
     * @param Closure(Table): list<Index> $indexes the indexes a table has in the database, without
     *        its primary key: Table::getIndexes() also lists indexes DBAL only assumes, such as
     *        one for each SQLite foreign key
     * @param string|null $defaultCollation the database's own default, which columns do not repeat
     */
    public function __construct(
        array $tables,
        private readonly Closure $indexes,
        private readonly ?string $defaultCollation = null,
        private readonly string $indent = '            ',
    ) {
        $this->types = array_flip(array_filter((new ReflectionClass(Types::class))->getConstants(), is_string(...)));
        $this->code = implode("\n", array_map($this->table(...), $tables));
        $classes = array_keys($this->used);
        sort($classes);
        $this->classes = $classes;
    }

    private function table(Table $table): string
    {
        $this->use(Table::class);
        $name = $table->getObjectName();
        $qualifier = $name->getQualifier();
        $nameCall = $this->nameCall($name->getUnqualifiedName());
        if ($qualifier !== null && $qualifier->getValue() !== 'public') {
            $nameCall = substr($nameCall, 0, -1) . ', ' . self::export($qualifier->getValue()) . ')';
        }
        $charset = $table->hasOption('charset') ? $table->getOption('charset') : null;
        $collation = $table->hasOption('collation') ? $table->getOption('collation') : null;
        $calls = [
            $nameCall,
            $this->list('setColumns', array_map(fn(Column $column): string => $this->column($column, $charset, $collation), $table->getColumns())),
        ];
        $primaryKey = $table->getPrimaryKeyConstraint();
        if ($primaryKey !== null) {
            $calls[] = '->setPrimaryKeyConstraint(' . $this->primaryKey($primaryKey) . ')';
        }
        $indexes = ($this->indexes)($table);
        if ($indexes !== []) {
            $calls[] = $this->list('setIndexes', array_map($this->index(...), $indexes));
        }
        if ($table->getUniqueConstraints() !== []) {
            $calls[] = $this->list('setUniqueConstraints', array_map($this->uniqueConstraint(...), array_values($table->getUniqueConstraints())));
        }
        if ($table->getForeignKeys() !== []) {
            $calls[] = $this->list('setForeignKeyConstraints', array_map($this->foreignKey(...), array_values($table->getForeignKeys())));
        }
        if ($table->getComment() !== null && $table->getComment() !== '') {
            $calls[] = '->setComment(' . self::export($table->getComment()) . ')';
        }
        $options = array_filter(
            array_intersect_key($table->getOptions(), array_flip(self::TABLE_OPTIONS)),
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        );
        if ($options !== []) {
            $calls[] = '->setOptions(' . self::export($options) . ')';
        }
        $calls[] = '->create(),';

        return $this->indent . "Table::editor()\n" . implode("\n", array_map(fn(string $call): string => $this->indent . '    ' . $call, $calls));
    }

    private function column(Column $column, mixed $tableCharset, mixed $tableCollation): string
    {
        $this->use(Column::class);
        $typeName = $column->getTypeName();
        if (isset($this->types[$typeName])) {
            $this->use(Types::class);
            $type = 'Types::' . $this->types[$typeName];
        } else {
            $type = self::export($typeName);
        }
        $code = 'Column::editor()' . $this->nameCall($column->getObjectName()->getIdentifier()) . "->setTypeName({$type})";
        $settings = [
            'setLength' => $column->getLength(),
            'setPrecision' => $column->getPrecision(),
            'setScale' => $column->getScale() === 0 ? null : $column->getScale(),
            'setUnsigned' => $column->getUnsigned() ?: null,
            'setFixed' => $column->getFixed() ?: null,
            'setNotNull' => $column->getNotnull() ? null : false,
            'setDefaultValue' => $column->getDefault(),
            'setAutoincrement' => $column->getAutoincrement() ?: null,
            'setComment' => $column->getComment() === '' ? null : $column->getComment(),
            'setValues' => $column->getValues() === [] ? null : $column->getValues(),
            // A column that inherits its table's character set and collation does not repeat them.
            'setCharset' => $column->getCharset() === $tableCharset ? null : $column->getCharset(),
            'setCollation' => in_array($column->getCollation(), [$tableCollation, $this->defaultCollation], true) ? null : $column->getCollation(),
            'setMinimumValue' => $column->getMinimumValue(),
            'setMaximumValue' => $column->getMaximumValue(),
            'setEnumType' => $column->getEnumType(),
            'setDefaultConstraintName' => $column->getDefaultConstraintName(),
            'setColumnDefinition' => $column->getColumnDefinition(),
        ];
        foreach ($settings as $method => $value) {
            if ($value !== null) {
                $code .= "->{$method}(" . self::export($value) . ')';
            }
        }

        return $code . '->create()';
    }

    private function primaryKey(PrimaryKeyConstraint $primaryKey): string
    {
        $this->use(PrimaryKeyConstraint::class);

        return 'PrimaryKeyConstraint::editor()' . $this->namesCall('ColumnNames', $primaryKey->getColumnNames()) . '->create()';
    }

    private function index(Index $index): string
    {
        $this->use(Index::class);
        $code = 'Index::editor()' . $this->nameCall($index->getObjectName()->getIdentifier());
        if ($index->getType() !== IndexType::REGULAR) {
            $this->use(IndexType::class);
            $code .= '->setType(IndexType::' . $index->getType()->name . ')';
        }
        $columns = $index->getIndexedColumns();
        $lengths = array_filter($columns, static fn(IndexedColumn $column): bool => $column->getLength() !== null);
        if ($lengths === []) {
            $code .= $this->namesCall('ColumnNames', array_map(static fn(IndexedColumn $column): UnqualifiedName => $column->getColumnName(), $columns));
        } else {
            // Prefix lengths, as MySQL needs for long text: the first N characters are indexed.
            $this->use(IndexedColumn::class);
            $this->use(UnqualifiedName::class);
            $code .= '->setColumns(' . implode(', ', array_map(static function (IndexedColumn $column): string {
                $name = $column->getColumnName()->getIdentifier();
                $factory = self::isPlain($name) ? 'unquoted' : 'quoted';

                return "new IndexedColumn(UnqualifiedName::{$factory}(" . self::export($name->getValue()) . '), ' . self::export($column->getLength()) . ')';
            }, $columns)) . ')';
        }
        if ($index->getPredicate() !== null) {
            $code .= '->setPredicate(' . self::export($index->getPredicate()) . ')';
        }

        return $code . '->create()';
    }

    private function uniqueConstraint(UniqueConstraint $constraint): string
    {
        $this->use(UniqueConstraint::class);
        $name = $constraint->getObjectName();

        return 'UniqueConstraint::editor()' . ($name === null ? '' : $this->nameCall($name->getIdentifier())) . $this->namesCall('ColumnNames', $constraint->getColumnNames()) . '->create()';
    }

    private function foreignKey(ForeignKeyConstraint $foreignKey): string
    {
        $this->use(ForeignKeyConstraint::class);
        $name = $foreignKey->getObjectName();
        $code = 'ForeignKeyConstraint::editor()' . ($name === null ? '' : $this->nameCall($name->getIdentifier()))
            . $this->namesCall('ReferencingColumnNames', $foreignKey->getReferencingColumnNames());
        $referenced = $foreignKey->getReferencedTableName()->getUnqualifiedName();
        $code .= (self::isPlain($referenced) ? '->setUnquotedReferencedTableName(' : '->setQuotedReferencedTableName(') . self::export($referenced->getValue()) . ')'
            . $this->namesCall('ReferencedColumnNames', $foreignKey->getReferencedColumnNames());
        foreach (['setOnDeleteAction' => $foreignKey->getOnDeleteAction(), 'setOnUpdateAction' => $foreignKey->getOnUpdateAction()] as $method => $action) {
            if ($action !== ReferentialAction::NO_ACTION) {
                $this->use(ReferentialAction::class);
                $code .= "->{$method}(ReferentialAction::{$action->name})";
            }
        }
        if ($foreignKey->getMatchType() !== MatchType::SIMPLE) {
            $this->use(MatchType::class);
            $code .= '->setMatchType(MatchType::' . $foreignKey->getMatchType()->name . ')';
        }
        if ($foreignKey->getDeferrability() !== Deferrability::NOT_DEFERRABLE) {
            $this->use(Deferrability::class);
            $code .= '->setDeferrability(Deferrability::' . $foreignKey->getDeferrability()->name . ')';
        }

        return $code . '->create()';
    }

    /** @param list<string> $items */
    private function list(string $method, array $items): string
    {
        $inner = $this->indent . '        ';

        return "->{$method}(\n" . implode("\n", array_map(static fn(string $item): string => "{$inner}{$item},", $items)) . "\n{$this->indent}    )";
    }

    private function nameCall(Identifier $identifier): string
    {

        return (self::isPlain($identifier) ? '->setUnquotedName(' : '->setQuotedName(') . self::export($identifier->getValue()) . ')';
    }

    /** @param list<UnqualifiedName> $names */
    private function namesCall(string $what, array $names): string
    {
        $identifiers = array_map(static fn(UnqualifiedName $name): Identifier => $name->getIdentifier(), $names);
        $plain = array_filter($identifiers, self::isPlain(...)) === $identifiers;

        return '->set' . ($plain ? 'Unquoted' : 'Quoted') . $what . '(' . implode(', ', array_map(static fn(Identifier $identifier): string => self::export($identifier->getValue()), $identifiers)) . ')';
    }

    /** Lowercase names need no quotes anywhere: no database folds them to something else. */
    private static function isPlain(Identifier $identifier): bool
    {
        return preg_match('/\A[a-z_][a-z0-9_]*\z/', $identifier->getValue()) === 1;
    }

    /** @param class-string $class */
    private function use(string $class): void
    {
        $this->used[$class] = true;
    }

    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : self::export($key) . ' => ') . self::export($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return $value === null ? 'null' : var_export($value, true);
    }
}
