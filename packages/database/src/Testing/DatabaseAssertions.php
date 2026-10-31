<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Assert;

/**
 * Assertions on tables, for a test case that has the skeleton's application() method:
 *
 *     $this->assertDatabaseHas('payments', ['reference' => 'PAY-1', 'status' => 'paid']);
 *     $this->assertDatabaseMissing('payments', ['reference' => 'PAY-2']);
 *     $this->assertDatabaseCount('payments', 3);
 *
 * Table and column names are used as given. A null value matches NULL. The default connection
 * is used unless one is named.
 */
trait DatabaseAssertions
{
    abstract protected function application(): Application;

    /** @param array<string, mixed> $where column => value */
    protected function assertDatabaseHas(string $table, array $where, ?string $connection = null): void
    {
        $db = $this->databaseConnection($connection);
        Assert::assertGreaterThan(0, self::databaseRows($db, $table, $where), sprintf(
            'Failed asserting that a row in %s matches %s.%s',
            $table,
            json_encode($where),
            self::databaseSample($db, $table, $where),
        ));
    }

    /** @param array<string, mixed> $where column => value */
    protected function assertDatabaseMissing(string $table, array $where, ?string $connection = null): void
    {
        $rows = self::databaseRows($this->databaseConnection($connection), $table, $where);
        Assert::assertSame(0, $rows, sprintf('Failed asserting that no row in %s matches %s: %d do.', $table, json_encode($where), $rows));
    }

    protected function assertDatabaseCount(string $table, int $count, ?string $connection = null): void
    {
        $rows = self::databaseRows($this->databaseConnection($connection), $table, []);
        Assert::assertSame($count, $rows, "Failed asserting that {$table} has {$count} rows: it has {$rows}.");
    }

    protected function assertDatabaseEmpty(string $table, ?string $connection = null): void
    {
        $this->assertDatabaseCount($table, 0, $connection);
    }

    private function databaseConnection(?string $name): Connection
    {
        return $this->application()->get(ConnectionManager::class)->connection($name);
    }

    /** @param array<string, mixed> $where */
    private static function databaseRows(Connection $db, string $table, array $where): int
    {
        [$sql, $parameters, $types] = self::databaseWhere($where);

        return (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}{$sql}", $parameters, $types);
    }

    /**
     * Up to five rows that match the first condition, for a failure message.
     *
     * @param array<string, mixed> $where
     */
    private static function databaseSample(Connection $db, string $table, array $where): string
    {
        $first = array_key_first($where);
        [$sql, $parameters, $types] = self::databaseWhere($first === null ? [] : [$first => $where[$first]]);
        $rows = $db->fetchAllAssociative($db->getDatabasePlatform()->modifyLimitQuery("SELECT * FROM {$table}{$sql}", 5), $parameters, $types);
        if ($rows === []) {
            return $first === null ? ' The table is empty.' : " No row has {$first} = " . json_encode($where[$first]) . '.';
        }

        return ($first === null ? ' Rows:' : " Rows with {$first} = " . json_encode($where[$first]) . ':') . "\n" . json_encode($rows, JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string, mixed> $where
     * @return array{string, list<mixed>, list<ParameterType>} the WHERE clause, its values, and their types
     */
    private static function databaseWhere(array $where): array
    {
        $conditions = [];
        $parameters = [];
        $types = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = "{$column} IS NULL";
                continue;
            }
            $conditions[] = "{$column} = ?";
            $parameters[] = $value;
            $types[] = match (true) {
                is_bool($value) => ParameterType::BOOLEAN,
                is_int($value) => ParameterType::INTEGER,
                default => ParameterType::STRING,
            };
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters, $types];
    }
}
