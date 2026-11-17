<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DecimalType;
use InvalidArgumentException;
use Stringable;

/**
 * One table, as a seeder writes to it. sync() is the heart: it makes the table hold the rows
 * given, matched by a key, and changes only what differs, so running it again changes nothing.
 *
 * Values are PHP values: true and false for boolean columns, DateTimeImmutable or a date string
 * for date columns, arrays for JSON columns, and backed enums for their values.
 */
final class SeedTable
{
    private const CHUNK = 500;

    private ?TableShape $shape = null;

    /** @internal Use Seed::table(). */
    public function __construct(
        private readonly Connection $db,
        private readonly string $name,
        private readonly TableReport $report,
    ) {}

    /**
     * Makes the table hold $rows. A row whose key is not in the table is inserted; one whose
     * other columns differ is updated; the rest are left alone. Rows of the table that are not
     * listed stay, unless $prune is true: then they are deleted.
     *
     * @param string|list<string> $key the column, or columns, that identify a row: a natural key such as a slug
     * @param list<array<string, mixed>> $rows values by column name; each row has every key column
     * @param list<string> $insertOnly columns written when a row is inserted and never compared or
     *                                 updated afterwards: defaults that may be changed later, and
     *                                 values such as password hashes that differ on every run
     */
    public function sync(string|array $key, array $rows, array $insertOnly = [], bool $prune = false): SyncResult
    {
        $keys = $this->keyColumns($key);
        $rows = $this->rows($rows);
        foreach ($insertOnly as $column) {
            if (in_array($column, $keys, true)) {
                throw new InvalidArgumentException("insertOnly names columns of {$this->name} that are written only when a row is inserted; it cannot name a key column.");
            }
        }
        $wanted = [];
        foreach ($rows as $row) {
            $id = $this->keyOf($row, $keys, false);
            if (isset($wanted[$id])) {
                throw new InvalidArgumentException("Two rows for {$this->name} have the same key: {$this->label($row, $keys)}.");
            }
            $wanted[$id] = $row;
        }
        $found = [];
        foreach ($prune ? $this->all() : $this->having($keys[0], array_column($rows, $keys[0])) as $existing) {
            $found[$this->keyOf($existing, $keys, true)] = $existing;
        }

        $inserted = $updated = $unchanged = $deleted = 0;
        foreach ($wanted as $id => $row) {
            if (!isset($found[$id])) {
                $this->insertRow($row);
                ++$inserted;
                $this->report->change('+ ' . $this->label($row, $keys));
                continue;
            }
            $changes = [];
            foreach ($row as $column => $value) {
                if (in_array($column, $keys, true) || in_array($column, $insertOnly, true)) {
                    continue;
                }
                if (!array_key_exists($column, $found[$id]) || !$this->same($column, $value, $found[$id][$column])) {
                    $changes[$column] = $value;
                }
            }
            if ($changes === []) {
                ++$unchanged;
                continue;
            }
            $this->updateRow($changes, $found[$id], $keys);
            ++$updated;
            $this->report->change('~ ' . $this->label($row, $keys) . ' (' . implode(', ', array_keys($changes)) . ')');
        }
        if ($prune) {
            foreach ($found as $id => $existing) {
                if (!isset($wanted[$id])) {
                    $this->deleteRow($existing, $keys);
                    ++$deleted;
                    $this->report->change('- ' . $this->label($existing, $keys));
                }
            }
        }
        $this->report->add($inserted, $updated, $unchanged, $deleted);

        return new SyncResult($inserted, $updated, $unchanged, $deleted);
    }

    /**
     * Inserts rows as they are, without looking at what the table holds. Running it again inserts
     * them again, so guard it with whenEmpty(), or prefer sync().
     *
     * @param list<array<string, mixed>> $rows
     * @return int the number of rows inserted
     */
    public function insert(array $rows): int
    {
        $rows = $this->rows($rows);
        foreach ($rows as $row) {
            $this->insertRow($row);
        }
        $this->report->add(inserted: count($rows));

        return count($rows);
    }

    /**
     * Calls $fill with this table when it holds no rows: for bulk sample data without a natural
     * key, filled once.
     *
     * @param callable(self): mixed $fill
     * @return bool whether $fill was called
     */
    public function whenEmpty(callable $fill): bool
    {
        if (!$this->isEmpty()) {
            $this->report->note('left as it is, not empty');

            return false;
        }
        $fill($this);

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM ' . $this->shape()->sql . ' LIMIT 1') === false;
    }

    /** @param array<string, mixed> $where column => value; a null value matches NULL */
    public function count(array $where = []): int
    {
        $shape = $this->shape();
        $conditions = $values = $types = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = $shape->column($column) . ' IS NULL';
                continue;
            }
            $conditions[] = $shape->column($column) . ' = ?';
            [$values[], $types[]] = $this->databaseValue($column, $value);
        }

        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $shape->sql . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions)), $values, $types);
    }

    /**
     * The table as a lookup: each row's $key value to its $value column, or to its primary key
     * when no column is named. For foreign keys: $seed->table('roles')->map('slug')['admin'].
     *
     * @return array<array-key, mixed>
     */
    public function map(string $key, ?string $value = null): array
    {
        $shape = $this->shape();
        if ($value === null) {
            if (count($shape->primaryKey) !== 1) {
                throw new InvalidArgumentException("Name the column to map {$this->name}.{$key} to: the table's primary key " . ($shape->primaryKey === [] ? 'is not known.' : 'has several columns.'));
            }
            $value = $shape->primaryKey[0];
        }
        $map = [];
        $sql = sprintf('SELECT %s AS seed_key, %s AS seed_value FROM %s', $shape->column($key), $shape->column($value), $shape->sql);
        foreach ($this->db->fetchAllAssociative($sql) as $row) {
            $id = $this->php($key, $row['seed_key']);
            $map[is_int($id) ? $id : self::canonical($id)] = $this->php($value, $row['seed_value']);
        }

        return $map;
    }

    private function shape(): TableShape
    {
        return $this->shape ??= TableShape::of($this->db, $this->name);
    }

    /**
     * @param string|list<string> $key
     * @return non-empty-list<string>
     */
    private function keyColumns(string|array $key): array
    {
        $keys = is_string($key) ? [$key] : $key;
        if ($keys === [] || in_array('', $keys, true) || count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException("The key of {$this->name} must be a column name, or a list of different column names.");
        }
        foreach ($keys as $column) {
            $this->shape()->column($column);
        }

        return $keys;
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        $checked = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || $row === [] || array_is_list($row)) {
                throw new InvalidArgumentException("Row {$index} for {$this->name} must be an array of values by column name.");
            }
            foreach (array_keys($row) as $column) {
                if (!is_string($column)) {
                    throw new InvalidArgumentException("Row {$index} for {$this->name} must be an array of values by column name.");
                }
                $this->shape()->column($column);
            }
            /** @var array<string, mixed> $row */
            $checked[] = $row;
        }

        return $checked;
    }

    /**
     * A row's key as one string, the same for a row to seed and the row the database holds.
     *
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function keyOf(array $row, array $keys, bool $fromDatabase): string
    {
        $parts = [];
        foreach ($keys as $column) {
            $value = $row[$column] ?? null;
            if ($fromDatabase) {
                $parts[] = self::canonical($this->comparable($column, $value));
                continue;
            }
            if ($value === null) {
                throw new InvalidArgumentException("A row for {$this->name} has no value for its key column {$column}.");
            }
            $parts[] = self::canonical($this->comparable($column, $this->databaseValue($column, $value)[0]));
        }

        return implode("\x1f", $parts);
    }

    /**
     * Such as "slug=admin", for the report.
     *
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function label(array $row, array $keys): string
    {
        $parts = [];
        foreach ($keys as $column) {
            $value = $row[$column] ?? null;
            $text = (string) preg_replace('/[\x00-\x1f]+/', ' ', self::canonical($value instanceof BackedEnum ? $value->value : $value));
            $parts[] = $column . '=' . (strlen($text) > 60 ? substr($text, 0, 57) . '...' : $text);
        }

        return implode(', ', $parts);
    }

    /** @return list<array<string, mixed>> */
    private function all(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ' . $this->shape()->sql);
    }

    /**
     * The rows whose $column is one of $values.
     *
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function having(string $column, array $values): array
    {
        $wanted = [];
        foreach ($values as $value) {
            $wanted[] = $this->databaseValue($column, $value)[0];
        }
        $wanted = array_values(array_unique($wanted, SORT_REGULAR));
        $sql = sprintf('SELECT * FROM %s WHERE %s IN (?)', $this->shape()->sql, $this->shape()->column($column));
        $rows = [];
        foreach (array_chunk($wanted, self::CHUNK) as $chunk) {
            $type = array_all($chunk, static fn(mixed $value): bool => is_int($value)) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING;
            array_push($rows, ...$this->db->fetchAllAssociative($sql, [$chunk], [$type]));
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function insertRow(array $row): void
    {
        $data = $types = [];
        foreach ($row as $column => $value) {
            $sql = $this->shape()->column($column);
            [$data[$sql], $types[$sql]] = $this->databaseValue($column, $value);
        }
        $this->db->insert($this->shape()->sql, $data, $types);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $existing the row as the database returned it
     * @param list<string> $keys
     */
    private function updateRow(array $changes, array $existing, array $keys): void
    {
        $data = $types = [];
        foreach ($changes as $column => $value) {
            $sql = $this->shape()->column($column);
            [$data[$sql], $types[$sql]] = $this->databaseValue($column, $value);
        }
        [$criteria, $criteriaTypes] = $this->criteria($existing, $keys);
        $this->db->update($this->shape()->sql, $data, $criteria, $types + $criteriaTypes);
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $keys
     */
    private function deleteRow(array $existing, array $keys): void
    {
        [$criteria, $types] = $this->criteria($existing, $keys);
        $this->db->delete($this->shape()->sql, $criteria, $types);
    }

    /**
     * The key of a row the database returned, as criteria for an UPDATE or a DELETE.
     *
     * @param array<string, mixed> $existing
     * @param list<string> $keys
     * @return array{array<string, mixed>, array<string, ParameterType>}
     */
    private function criteria(array $existing, array $keys): array
    {
        $criteria = $types = [];
        foreach ($keys as $column) {
            $sql = $this->shape()->column($column);
            $criteria[$sql] = $existing[$column] ?? null;
            $types[$sql] = self::parameterType($criteria[$sql]);
        }

        return [$criteria, $types];
    }

    /**
     * A PHP value as the database takes it, with how to bind it. A column's DBAL type converts
     * it; a plain value that type refuses, such as a date written as a string, is passed as it is.
     *
     * @return array{mixed, ParameterType}
     */
    private function databaseValue(string $column, mixed $value): array
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $type = $this->shape()->type($column);
        // MySQL reads every TINYINT column as a boolean: a number for one is written as the number.
        if ($type !== null && !($type instanceof BooleanType && !is_bool($value))) {
            // A date column takes DateTime or DateTimeImmutable, depending on how the database
            // describes it; a seeder may give either.
            $forms = match (true) {
                $value instanceof DateTimeImmutable => [$value, DateTime::createFromImmutable($value)],
                $value instanceof DateTime => [$value, DateTimeImmutable::createFromMutable($value)],
                default => [$value],
            };
            $refused = null;
            foreach ($forms as $form) {
                try {
                    $converted = $type->convertToDatabaseValue($form, $this->shape()->platform);
                } catch (ConversionException $exception) {
                    $refused = $exception;
                    continue;
                }
                $refused = null;
                // A type that leaves an array or an object as it is, such as a text column given an
                // array, is written by the value's PHP type below.
                if ($converted === null || is_scalar($converted) || is_resource($converted)) {
                    return [$converted, $type->getBindingType()];
                }
                break;
            }
            if ($refused !== null && !is_scalar($value)) {
                throw new InvalidArgumentException("{$this->name}.{$column} cannot take a value of type " . get_debug_type($value) . '.', previous: $refused);
            }
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value) => [$value, self::parameterType($value)],
            $value instanceof DateTimeInterface => [$value->format('Y-m-d H:i:s'), ParameterType::STRING],
            is_array($value) => [json_encode($value, JSON_THROW_ON_ERROR), ParameterType::STRING],
            is_scalar($value), $value instanceof Stringable => [(string) $value, ParameterType::STRING],
            default => throw new InvalidArgumentException("{$this->name}.{$column} cannot take a value of type " . get_debug_type($value) . '.'),
        };
    }

    /** A value the database returned, or would store, as the column's DBAL type reads it. */
    private function php(string $column, mixed $value): mixed
    {
        $type = $this->shape()->type($column);
        if ($type !== null) {
            try {
                $value = $type->convertToPHPValue($value, $this->shape()->platform);
            } catch (ConversionException) {
                // Kept as the database gave it.
            }
        }

        return is_resource($value) ? (string) stream_get_contents($value) : $value;
    }

    /**
     * A value the database returned, or would store, in the form two of them are compared in:
     * as php() reads it, except that a boolean column gives 1 or 0, or the number it holds.
     */
    private function comparable(string $column, mixed $value): mixed
    {
        if (!$this->shape()->type($column) instanceof BooleanType) {
            return $this->php($column, $value);
        }

        return match (true) {
            is_bool($value) => (int) $value,
            is_string($value) && in_array(strtolower($value), ['t', 'true'], true) => 1,
            is_string($value) && in_array(strtolower($value), ['f', 'false'], true) => 0,
            is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 => (int) $value,
            default => $value,
        };
    }

    /** Whether a value to seed is what the database already holds. */
    private function same(string $column, mixed $value, mixed $existing): bool
    {
        $type = $this->shape()->type($column);
        $new = $this->comparable($column, $this->databaseValue($column, $value)[0]);
        $old = $this->comparable($column, $existing);
        if ($new === null || $old === null) {
            return $new === $old;
        }
        if ($new instanceof DateTimeInterface && $old instanceof DateTimeInterface) {
            return $new == $old;
        }
        if (is_array($new) && is_array($old)) {
            return self::sameData($new, $old);
        }
        if (is_array($value) && is_string($new) && is_string($old)) {
            // An array kept as JSON in a text column.
            $held = json_decode($old, true);
            if (is_array($held)) {
                return self::sameData(json_decode($new, true), $held);
            }
        }
        if (is_bool($new) || is_bool($old)) {
            return self::truthy($new) === self::truthy($old);
        }
        if (is_float($new) || is_float($old)) {
            return is_numeric($new) && is_numeric($old)
                && abs((float) $new - (float) $old) <= 1e-9 * max(1.0, abs((float) $new), abs((float) $old));
        }
        if ($type instanceof DecimalType && is_numeric($new) && is_numeric($old)) {
            return self::decimal((string) $new) === self::decimal((string) $old);
        }

        return self::canonical($new) === self::canonical($old);
    }

    /** Whether two decoded JSON values hold the same data. The order of an object's keys does not count. */
    private static function sameData(mixed $one, mixed $other): bool
    {
        if (is_array($one) && is_array($other)) {
            if (count($one) !== count($other) || array_is_list($one) !== array_is_list($other)) {
                return false;
            }
            foreach ($one as $key => $item) {
                if (!array_key_exists($key, $other) || !self::sameData($item, $other[$key])) {
                    return false;
                }
            }

            return true;
        }
        if ((is_int($one) || is_float($one)) && (is_int($other) || is_float($other))) {
            return $one == $other;
        }

        return $one === $other;
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value) ? in_array(strtolower($value), ['1', 't', 'true', 'y', 'yes', 'on'], true) : (bool) $value;
    }

    /** "010.50" and "10.5" are the same number. */
    private static function decimal(string $number): string
    {
        if (preg_match('/\A([+-]?)(\d*)(?:\.(\d*))?\z/', trim($number), $match) !== 1) {
            return $number;
        }
        $integer = ltrim($match[2], '0');
        $fraction = rtrim($match[3] ?? '', '0');
        $value = ($integer === '' ? '0' : $integer) . ($fraction === '' ? '' : '.' . $fraction);

        return ($match[1] === '-' && $value !== '0' ? '-' : '') . $value;
    }

    private static function canonical(mixed $value): string
    {
        return match (true) {
            $value === null => "\0",
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:s.uP'),
            is_array($value) => (string) json_encode($value),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    private static function parameterType(mixed $value): ParameterType
    {
        return match (true) {
            $value === null => ParameterType::NULL,
            is_bool($value) => ParameterType::BOOLEAN,
            is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        };
    }
}
