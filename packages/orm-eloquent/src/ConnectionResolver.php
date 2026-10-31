<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Orm\Eloquent\Connection\XpsrMariaDbConnection;
use Crystoline\Xpsr\Orm\Eloquent\Connection\XpsrMySqlConnection;
use Crystoline\Xpsr\Orm\Eloquent\Connection\XpsrPostgresConnection;
use Crystoline\Xpsr\Orm\Eloquent\Connection\XpsrSQLiteConnection;
use Crystoline\Xpsr\Orm\Eloquent\Connection\XpsrSqlServerConnection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Eloquent's connections, one per XPSR connection of the same name (config/database.php), each
 * on the PDO that XPSR's DBAL connection opened. Models without a $connection use the default
 * XPSR connection. With a dispatcher, the connections fire Eloquent's query and transaction
 * events, so listen() and whenQueryingForLongerThan() work.
 */
final class ConnectionResolver implements ConnectionResolverInterface
{
    /** @var array<string, Connection> */
    private array $resolved = [];

    private ?string $default = null;

    public function __construct(private readonly ConnectionManager $connections, private readonly ?Dispatcher $events = null) {}

    /** @param string|null $name */
    public function connection($name = null): Connection
    {
        $name ??= $this->getDefaultConnection();

        return $this->resolved[$name] ??= $this->create($name);
    }

    public function getDefaultConnection(): string
    {
        return $this->default ?? $this->connections->defaultName();
    }

    /** @param string $name */
    public function setDefaultConnection($name): void
    {
        $this->default = $name;
    }

    private function create(string $name): Connection
    {
        $dbal = $this->connections->connection($name);
        $platform = $dbal->getDatabasePlatform();
        [$class, $driver] = match (true) {
            $platform instanceof MariaDBPlatform => [XpsrMariaDbConnection::class, 'mariadb'],
            $platform instanceof AbstractMySQLPlatform => [XpsrMySqlConnection::class, 'mysql'],
            $platform instanceof PostgreSQLPlatform => [XpsrPostgresConnection::class, 'pgsql'],
            $platform instanceof SQLitePlatform => [XpsrSQLiteConnection::class, 'sqlite'],
            $platform instanceof SQLServerPlatform => [XpsrSqlServerConnection::class, 'sqlsrv'],
            default => throw new InvalidArgumentException(sprintf('Eloquent does not support the %s database of connection "%s".', $platform::class, $name)),
        };
        $pdo = static function () use ($dbal, $name): PDO {
            $native = $dbal->getNativeConnection();

            return $native instanceof PDO
                ? $native
                : throw new LogicException("Eloquent needs a PDO driver; connection \"{$name}\" uses " . get_debug_type($native) . '. Use pdo_mysql, pdo_pgsql, pdo_sqlite, or pdo_sqlsrv.');
        };
        $database = $dbal->getDatabase();   // one query, on most platforms
        $connection = new $class($pdo, (string) $database, '', ['name' => $name, 'driver' => $driver, 'database' => $database, 'prefix' => '']);
        $connection->useXpsrConnection($dbal);
        if ($this->events !== null) {
            $connection->setEventDispatcher($this->events);
        }

        return $connection;
    }
}
