<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\QueryEvents;
use Crystoline\Xpsr\Orm\Cycle\Driver\XpsrMySQLDriver;
use Crystoline\Xpsr\Orm\Cycle\Driver\XpsrPostgresDriver;
use Crystoline\Xpsr\Orm\Cycle\Driver\XpsrSQLiteDriver;
use Crystoline\Xpsr\Orm\Cycle\Driver\XpsrSQLServerDriver;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\MySQL\TcpConnectionConfig as MySQLConnection;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig as PostgresConnection;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig as SQLiteConnection;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\Config\SQLServer\TcpConnectionConfig as SQLServerConnection;
use Cycle\Database\Config\SQLServerDriverConfig;
use Cycle\Database\Database;
use Cycle\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Cycle's databases, one per XPSR connection of the same name (config/database.php), each on the
 * PDO that XPSR's DBAL connection opened. Nothing connects until the first query runs. The
 * queries are reported to QueryEvents, as DBAL's and Eloquent's are.
 *
 * @internal
 */
final class Databases
{
    private const OPTIONS = ['logQueryParameters' => false];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ?string $default = null,
        private readonly ?QueryEvents $events = null,
        private readonly string $timezone = 'UTC',
    ) {}

    public function manager(): DatabaseManager
    {
        $manager = new DatabaseManager(new DatabaseConfig(['default' => $this->default ?? $this->connections->defaultName()]));
        foreach ($this->connections->names() as $name) {
            $driver = $this->driver($name);
            $manager->addDriver($name, $driver);
            $manager->addDatabase(new Database($name, '', $driver));
        }

        return $manager;
    }

    private function driver(string $name): XpsrSQLiteDriver|XpsrMySQLDriver|XpsrPostgresDriver|XpsrSQLServerDriver
    {
        $parameters = $this->connections->parameters($name);
        $database = $parameters['dbname'] ?? $parameters['path'] ?? $name;
        $database = is_string($database) ? $database : $name;
        $driver = match (self::type($parameters, $name)) {
            'sqlite' => XpsrSQLiteDriver::create(new SQLiteDriverConfig(new SQLiteConnection($database), XpsrSQLiteDriver::class, false, $this->timezone, false, false, false, self::OPTIONS)),
            'mysql' => XpsrMySQLDriver::create(new MySQLDriverConfig(new MySQLConnection($database), XpsrMySQLDriver::class, false, $this->timezone, false, false, false, self::OPTIONS)),
            'pgsql' => XpsrPostgresDriver::create(new PostgresDriverConfig(new PostgresConnection($database), PostgresDriverConfig::DEFAULT_SCHEMA, XpsrPostgresDriver::class, false, $this->timezone, false, false, false, self::OPTIONS)),
            default => XpsrSQLServerDriver::create(new SQLServerDriverConfig(new SQLServerConnection($database), XpsrSQLServerDriver::class, false, $this->timezone, false, false, false, self::OPTIONS)),
        };
        $driver->useXpsrConnection($this->connections->connection($name));
        if ($this->events !== null) {
            $driver->setLogger(new QueryLogger($this->events, $name));
        }

        return $driver;
    }

    /** @param array<string, mixed> $parameters */
    private static function type(array $parameters, string $name): string
    {
        $driver = $parameters['driver'] ?? null;
        $driver = is_string($driver) ? strtolower(str_replace('-', '_', $driver)) : '';

        return match (true) {
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'mysql'), str_contains($driver, 'mariadb') => 'mysql',
            str_contains($driver, 'pgsql'), str_contains($driver, 'postgres') => 'pgsql',
            str_contains($driver, 'sqlsrv'), str_contains($driver, 'mssql') => 'sqlsrv',
            default => throw new InvalidArgumentException("Cycle cannot use the driver of connection \"{$name}\": use pdo_sqlite, pdo_mysql, pdo_pgsql, or pdo_sqlsrv."),
        };
    }
}
