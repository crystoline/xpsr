<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database;

use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Database\Migration\Migrator;
use InvalidArgumentException;

/**
 * Builds a Migrator for a connection from the "database.migrations" configuration:
 *
 *   path   directory of migration files, relative to the application root (default migrations)
 *   table  table that records which migrations ran (default migrations)
 */
final class MigratorFactory implements MigratorFactoryInterface
{
    /** @param array<array-key, mixed> $config the "database.migrations" configuration */
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly array $config,
        private readonly string $rootDir,
    ) {}

    public function create(?string $connection = null): Migrator
    {
        $table = $this->config['table'] ?? 'migrations';
        if (!is_string($table)) {
            throw new InvalidArgumentException('database.migrations.table must be a table name.');
        }

        return new Migrator($this->connections->connection($connection), $this->directory(), $table);
    }

    /** The absolute migrations directory. */
    public function directory(): string
    {
        $path = $this->config['path'] ?? 'migrations';
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('database.migrations.path must be a directory path.');
        }
        $absolute = str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path) === 1;

        return rtrim($absolute ? $path : $this->rootDir . '/' . $path, '/\\');
    }

    /** The migrations directory as configured, for messages. */
    public function displayPath(): string
    {
        $path = $this->config['path'] ?? 'migrations';

        return is_string($path) ? rtrim($path, '/\\') : 'migrations';
    }
}
