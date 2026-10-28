<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Throwable;

/**
 * Runs and rolls back the migrations in one directory against one connection.
 *
 * Migration files are named <YYYYMMDDHHMMSS>_<name>.php (UTC) and run in name order. Each run
 * of migrate() is a batch; rollback() undoes the last batch, or a number of migrations.
 */
final class Migrator implements MigratorInterface
{
    public const FILE_PATTERN = '/\A\d{14}_[a-z0-9_]+\z/';

    private readonly MigrationRepository $repository;

    /** @var array<string, Migration> Files are loaded once per process: PHP cannot unload their classes. */
    private static array $loaded = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $directory,
        string $table = 'migrations',
    ) {
        $this->repository = new MigrationRepository($connection, $table);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Every migration file and every recorded migration, in order.
     *
     * @return list<array{migration: string, status: 'ran'|'pending'|'missing', batch: int|null}>
     *         "missing" means the migration ran but its file no longer exists
     */
    public function status(): array
    {
        $ran = $this->repository->ran();
        $files = $this->files();
        $names = array_unique([...array_keys($ran), ...array_keys($files)]);
        sort($names, SORT_STRING);

        return array_map(static fn(string $name): array => [
            'migration' => $name,
            'status' => isset($ran[$name]) ? (isset($files[$name]) ? 'ran' : 'missing') : 'pending',
            'batch' => $ran[$name] ?? null,
        ], $names);
    }

    /** @return list<string> pending migrations, in the order migrate() would run them */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->files()), array_keys($this->repository->ran())));
    }

    /**
     * Run every pending migration as one new batch.
     *
     * @param (callable(string): void)|null $progress called with each migration name before it runs
     * @return list<string> the migrations that ran
     */
    public function migrate(?callable $progress = null): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }
        $this->repository->create();
        $batch = $this->repository->lastBatch() + 1;
        $files = $this->files();
        $done = [];
        foreach ($pending as $name) {
            if ($progress !== null) {
                $progress($name);
            }
            $migration = $this->load($name, $files[$name]);
            $this->run($name, $migration, 'up', function () use ($name, $batch): void {
                $this->repository->log($name, $batch);
            });
            $done[] = $name;
        }

        return $done;
    }

    /**
     * Roll back the last batch, or the last $steps migrations, newest first.
     *
     * @param (callable(string): void)|null $progress called with each migration name before it is rolled back
     * @return list<string> the migrations that were rolled back
     */
    public function rollback(?int $steps = null, ?callable $progress = null): array
    {
        if ($steps !== null && $steps < 1) {
            throw new MigrationException('The number of migrations to roll back must be at least 1.');
        }
        $ran = $this->repository->ran();
        if ($ran === []) {
            return [];
        }
        $names = array_reverse(array_keys($ran));
        $targets = $steps === null
            ? array_values(array_filter($names, static fn(string $name): bool => $ran[$name] === max($ran)))
            : array_slice($names, 0, $steps);
        $files = $this->files();
        $done = [];
        foreach ($targets as $name) {
            if (!isset($files[$name])) {
                throw new MigrationException("Cannot roll back {$name}: it has run, but {$this->directory}/{$name}.php no longer exists.");
            }
            if ($progress !== null) {
                $progress($name);
            }
            $migration = $this->load($name, $files[$name]);
            $this->run($name, $migration, 'down', function () use ($name): void {
                $this->repository->delete($name);
            });
            $done[] = $name;
        }

        return $done;
    }

    /** @return array<string, string> migration name => file path, in name order */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $files = [];
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (!preg_match(self::FILE_PATTERN, $name)) {
                throw new MigrationException(sprintf(
                    'Migration file %s is not named like 20260131120000_create_users_table.php. Rename it, or move it out of %s.',
                    basename($file),
                    $this->directory,
                ));
            }
            $files[$name] = $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function load(string $name, string $file): Migration
    {
        if (isset(self::$loaded[$file])) {
            return self::$loaded[$file];
        }
        $migration = (static fn(string $path): mixed => require $path)($file);
        if (!$migration instanceof Migration) {
            throw new MigrationException("Migration {$name} must return an instance of " . Migration::class . ', for example: return new class extends Migration { ... };');
        }

        return self::$loaded[$file] = $migration;
    }

    /** @param 'up'|'down' $direction */
    private function run(string $name, Migration $migration, string $direction, callable $record): void
    {
        $transactional = $migration->isTransactional() && self::supportsTransactionalDdl($this->connection);
        try {
            if ($transactional) {
                $this->connection->transactional(static function (Connection $connection) use ($migration, $direction, $record): void {
                    $migration->{$direction}($connection);
                    $record();
                });
            } else {
                $migration->{$direction}($this->connection);
                $record();
            }
        } catch (Throwable $exception) {
            $action = $direction === 'up' ? 'Migration' : 'Rollback of';
            throw new MigrationException("{$action} {$name} failed: {$exception->getMessage()}", 0, $exception);
        }
    }

    /** MySQL, MariaDB, and Oracle commit implicitly after schema changes. */
    private static function supportsTransactionalDdl(Connection $connection): bool
    {
        $platform = $connection->getDatabasePlatform();

        return !$platform instanceof AbstractMySQLPlatform && !$platform instanceof OraclePlatform;
    }
}
