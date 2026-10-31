<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

/**
 * The databases tests run against: migrated from nothing once per process, and emptied.
 *
 * @internal Used by RefreshDatabase and DatabaseTruncation.
 */
final class TestDatabases
{
    /** @var array<string, true> the connections migrated from nothing in this process, by their settings */
    private static array $fresh = [];

    /**
     * Drops every table, view, and sequence on the connection and runs the migrations, once per
     * process; and again whenever the migrations table is gone, as on an in-memory SQLite
     * database, which is new for every test. Refused when app.env is production.
     */
    public static function refresh(Application $application, ?string $connection = null): void
    {
        $manager = $application->get(ConnectionManager::class);
        $connection ??= $manager->defaultName();
        $key = $connection . ':' . hash('sha256', serialize($manager->parameters($connection)));
        $db = $manager->connection($connection);
        if (isset(self::$fresh[$key]) && $db->createSchemaManager()->tableExists(self::migrationsTable($application))) {
            return;
        }
        if ($application->getConfig()->get('app.env') === 'production') {
            throw new LogicException("RefreshDatabase drops every table on the \"{$connection}\" connection. It refused because app.env is production.");
        }
        self::dropEverything($db);
        $application->get(MigratorFactoryInterface::class)->create($connection)->migrate();
        self::$fresh[$key] = true;
    }

    /**
     * Empties every table on the connection but the migrations table and $keep, and resets
     * auto-increment counters where the database can.
     *
     * @param list<string> $keep
     */
    public static function truncate(Application $application, ?string $connection, array $keep): void
    {
        $manager = $application->get(ConnectionManager::class);
        $db = $manager->connection($connection ?? $manager->defaultName());
        $platform = $db->getDatabasePlatform();
        $keep[] = self::migrationsTable($application);
        $tables = [];
        foreach ($db->createSchemaManager()->introspectSchema()->getTables() as $table) {
            $name = $table->getObjectName();
            if (!in_array($name->toString(), $keep, true)) {
                $tables[] = $name->toSQL($platform);
            }
        }
        if ($tables === []) {
            return;
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $db->executeStatement('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');

            return;
        }
        self::withoutForeignKeyChecks($db, static function () use ($db, $platform, $tables): void {
            foreach ($tables as $table) {
                $db->executeStatement($platform->getTruncateTableSQL($table));
            }
            // DBAL's schema manager hides SQLite's own tables, so ask SQLite whether the counters exist.
            if ($platform instanceof SQLitePlatform && $db->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'") !== false) {
                $db->executeStatement('DELETE FROM sqlite_sequence');
            }
        });
    }

    private static function dropEverything(Connection $db): void
    {
        $schema = $db->createSchemaManager();
        $platform = $db->getDatabasePlatform();
        self::withoutForeignKeyChecks($db, static function () use ($schema, $platform): void {
            foreach ($schema->listViews() as $view) {
                $schema->dropView($view->getObjectName()->toSQL($platform));
            }
            // Foreign keys first, then tables and sequences, in an order the database accepts.
            $schema->dropSchemaObjects($schema->introspectSchema());
        });
    }

    /** @param callable(): void $work */
    private static function withoutForeignKeyChecks(Connection $db, callable $work): void
    {
        $platform = $db->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $work();
            } finally {
                $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }

            return;
        }
        if ($platform instanceof SQLitePlatform && (int) $db->fetchOne('PRAGMA foreign_keys') === 1) {
            $db->executeStatement('PRAGMA foreign_keys = OFF');
            try {
                $work();
            } finally {
                $db->executeStatement('PRAGMA foreign_keys = ON');
            }

            return;
        }
        $work();
    }

    private static function migrationsTable(Application $application): string
    {
        $table = $application->get(Configuration::class)->get('database.migrations.table', 'migrations');

        return is_string($table) && $table !== '' ? $table : 'migrations';
    }
}
