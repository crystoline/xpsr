<?php

declare(strict_types=1);

namespace Crystoline\Database;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Tools\DsnParser;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Creates the configured database connections on first use and keeps one per name.
 *
 * Connections are Doctrine DBAL connections. DBAL connects to the server only when the first
 * query runs, so asking for a connection is cheap.
 *
 * Configuration (the "database" configuration, usually config/database.php):
 *
 *   default      name of the default connection
 *   connections  name => DBAL connection parameters, for example
 *                ['driver' => 'pdo_mysql', 'host' => ..., 'dbname' => ..., 'user' => ..., 'password' => ...]
 *                or ['url' => 'mysql://user:secret@localhost/app']. A relative SQLite "path" is
 *                resolved against the application root.
 *   log_queries  true to log every query at debug level through the application logger
 */
final class ConnectionManager
{
    /** URL schemes accepted in "url", mapped to DBAL drivers. */
    public const URL_SCHEMES = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
    ];

    /** @var array<string, Connection> */
    private array $connections = [];

    /** @param array<array-key, mixed> $config the "database" configuration */
    public function __construct(
        private readonly array $config,
        private readonly string $rootDir,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** The connection with this name, or the default connection. */
    public function connection(?string $name = null): Connection
    {
        $name ??= $this->defaultName();

        return $this->connections[$name] ??= $this->create($name);
    }

    public function defaultName(): string
    {
        $default = $this->config['default'] ?? null;
        if (!is_string($default) || $default === '') {
            throw new InvalidArgumentException('database.default must name one of database.connections.');
        }

        return $default;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->connectionsConfig()));
    }

    /**
     * The DBAL parameters for a connection, after URL parsing and path resolution.
     *
     * @return array<string, mixed>
     */
    public function parameters(string $name): array
    {
        $connections = $this->connectionsConfig();
        if (!isset($connections[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown database connection "%s". Configured connections: %s.',
                $name,
                $connections === [] ? 'none' : implode(', ', array_keys($connections)),
            ));
        }
        $parameters = $connections[$name];
        if (!is_array($parameters)) {
            throw new InvalidArgumentException("database.connections.{$name} must be an array of connection parameters.");
        }
        $url = $parameters['url'] ?? null;
        unset($parameters['url']);
        if ($url !== null && $url !== '') {
            if (!is_string($url)) {
                throw new InvalidArgumentException("database.connections.{$name}.url must be a string.");
            }
            // parse_url() rejects sqlite:///relative/path, so read the scheme directly.
            $scheme = preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $url, $match) === 1 ? strtolower($match[1]) : '';
            if (!isset(self::URL_SCHEMES[$scheme])) {
                throw new InvalidArgumentException(sprintf(
                    'database.connections.%s.url must start with %s.',
                    $name,
                    implode('://, ', array_keys(self::URL_SCHEMES)) . '://',
                ));
            }
            $parameters = [...$parameters, ...(new DsnParser(self::URL_SCHEMES))->parse($url)];
        }
        if (!isset($parameters['driver']) && !isset($parameters['driverClass'])) {
            throw new InvalidArgumentException("database.connections.{$name} needs a \"driver\", such as pdo_sqlite, pdo_mysql, or pdo_pgsql, or a \"url\".");
        }
        if (isset($parameters['path']) && is_string($parameters['path']) && $parameters['path'] !== '' && !self::isAbsolute($parameters['path'])) {
            $parameters['path'] = $this->rootDir . '/' . $parameters['path'];
        }

        /** @var array<string, mixed> $parameters */
        return $parameters;
    }

    private function create(string $name): Connection
    {
        $parameters = $this->parameters($name);
        $configuration = new DbalConfiguration();
        if (($this->config['log_queries'] ?? false) === true && $this->logger !== null) {
            $configuration->setMiddlewares([new LoggingMiddleware($this->logger)]);
        }
        if (in_array($parameters['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true) && isset($parameters['path']) && is_string($parameters['path'])) {
            // SQLite creates the database file, but not its directory.
            $directory = dirname($parameters['path']);
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new InvalidArgumentException("Could not create the directory for the SQLite database {$parameters['path']}.");
            }
        }

        return DriverManager::getConnection($parameters, $configuration);
    }

    /** @return array<array-key, mixed> */
    private function connectionsConfig(): array
    {
        $connections = $this->config['connections'] ?? [];
        if (!is_array($connections)) {
            throw new InvalidArgumentException('database.connections must map connection names to connection parameters.');
        }

        return $connections;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
