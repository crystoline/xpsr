<?php

declare(strict_types=1);

namespace Crystoline\Database;

use Crystoline\Application;
use Crystoline\Configuration;
use Crystoline\Console\CommandRegistry;
use Crystoline\Database\Console\MakeMigrationCommand;
use Crystoline\Database\Console\MigrateCommand;
use Crystoline\Database\Console\MigrateRollbackCommand;
use Crystoline\Database\Console\MigrateStatusCommand;
use Crystoline\ServiceContainer\ServiceRegistry;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers database connections and the migration commands:
 *
 *   Crystoline\Database\ConnectionManager  every configured connection, by name
 *   Doctrine\DBAL\Connection               the default connection
 *   Crystoline\Database\MigratorFactory    migrations for any connection
 *
 * and migrate, migrate:rollback, migrate:status, and make:migration when the console is part
 * of the application. Configuration lives in config/database.php.
 */
final class DatabaseServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(ConnectionManager::class, static function (ContainerInterface $container): ConnectionManager {
            return new ConnectionManager(
                self::config($container),
                $container->get(Application::class)->getRootDir(),
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            );
        }, shared: true);
        $this->container->factory(
            Connection::class,
            static fn(ContainerInterface $container): Connection => $container->get(ConnectionManager::class)->connection(),
            shared: true,
        );
        $this->container->factory(MigratorFactory::class, static function (ContainerInterface $container): MigratorFactory {
            $migrations = self::config($container)['migrations'] ?? [];
            if (!is_array($migrations)) {
                throw new InvalidArgumentException('database.migrations must be an array with "path" and "table".');
            }

            return new MigratorFactory(
                $container->get(ConnectionManager::class),
                $migrations,
                $container->get(Application::class)->getRootDir(),
            );
        }, shared: true);
        foreach ([MigrateCommand::class, MigrateRollbackCommand::class, MigrateStatusCommand::class, MakeMigrationCommand::class] as $command) {
            $this->container->bind($command);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('migrate', MigrateCommand::class, 'Run the pending database migrations.');
        $commands->register('migrate:rollback', MigrateRollbackCommand::class, 'Roll back the last batch of migrations.');
        $commands->register('migrate:status', MigrateStatusCommand::class, 'Show which migrations have run.');
        $commands->register('make:migration', MakeMigrationCommand::class, 'Create a migration file.');
    }

    /** @return array<array-key, mixed> */
    private static function config(ContainerInterface $container): array
    {
        $config = $container->get(Configuration::class)->get('database', []);
        if (!is_array($config)) {
            throw new InvalidArgumentException('The database configuration must be an array.');
        }

        return $config;
    }
}
