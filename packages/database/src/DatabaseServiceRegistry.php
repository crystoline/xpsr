<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\Console\MakeMigrationCommand;
use Crystoline\Xpsr\Database\Console\MigrateCommand;
use Crystoline\Xpsr\Database\Console\MigrateRollbackCommand;
use Crystoline\Xpsr\Auth\AuthOptions;
use Crystoline\Xpsr\Database\Auth\DatabaseUserProviderFactory;
use Crystoline\Xpsr\Database\Auth\TokenGuardFactory;
use Crystoline\Xpsr\Database\Auth\TokenRepository;
use Crystoline\Xpsr\Database\Console\CreateTokenCommand;
use Crystoline\Xpsr\Database\Console\MakeSessionTableCommand;
use Crystoline\Xpsr\Database\Console\MakeTokenTableCommand;
use Crystoline\Xpsr\Database\Console\RevokeTokenCommand;
use Crystoline\Xpsr\Database\Console\MigrateStatusCommand;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Database\Session\DatabaseSessionStore;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Session\SessionOptions;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers database connections and the migration commands:
 *
 *   Crystoline\Xpsr\Database\ConnectionManager                      every configured connection, by name
 *   Doctrine\DBAL\Connection                                        the default connection
 *   Crystoline\Xpsr\Database\MigratorFactory                        XPSR's migration runner
 *   Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface     the runner the commands use: the
 *                                                                   service in database.migrations.runner
 *
 * the session store for session.store "database", the "database" user provider and "token" guard
 * for config/auth.php, and migrate, migrate:rollback, migrate:status, make:migration,
 * make:session-table, make:token-table, auth:token, and auth:token-revoke when the console is
 * part of the application. Configuration lives in config/database.php.
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
        // The runner behind migrate, migrate:rollback, and migrate:status; replaceable by a package.
        $this->container->factory(MigratorFactoryInterface::class, static function (ContainerInterface $container): MigratorFactoryInterface {
            $migrations = self::config($container)['migrations'] ?? [];
            $runner = is_array($migrations) ? ($migrations['runner'] ?? MigratorFactory::class) : MigratorFactory::class;
            $factory = is_string($runner) && $runner !== '' ? $container->get($runner) : null;
            if (!$factory instanceof MigratorFactoryInterface) {
                throw new InvalidArgumentException('database.migrations.runner must name a service implementing ' . MigratorFactoryInterface::class . '.');
            }

            return $factory;
        }, shared: true);
        foreach ([MigrateCommand::class, MigrateRollbackCommand::class, MigrateStatusCommand::class, MakeMigrationCommand::class, MakeSessionTableCommand::class, MakeTokenTableCommand::class, CreateTokenCommand::class, RevokeTokenCommand::class] as $command) {
            $this->container->bind($command);
        }
        // auth.users driver "database" and auth.guards driver "token", resolved by the framework's AuthManager.
        $this->container->factory('auth.users.database', static fn(ContainerInterface $container): DatabaseUserProviderFactory => new DatabaseUserProviderFactory(
            $container->get(ConnectionManager::class),
        ), shared: true);
        $this->container->factory(TokenRepository::class, static function (ContainerInterface $container): TokenRepository {
            $auth = $container->get(AuthOptions::class);
            $tokens = $auth->tokens;
            foreach (array_keys($tokens) as $key) {
                if (!in_array($key, ['table', 'connection'], true)) {
                    throw new InvalidArgumentException("Unknown option auth.tokens.{$key}. Known options: table, connection.");
                }
            }
            $connection = $tokens['connection'] ?? null;
            $table = $tokens['table'] ?? 'api_tokens';
            if (($connection !== null && !is_string($connection)) || !is_string($table)) {
                throw new InvalidArgumentException('auth.tokens.table must be a table name, and auth.tokens.connection a connection name or null.');
            }

            return new TokenRepository($container->get(ConnectionManager::class)->connection($connection), $container->get(ClockInterface::class), $table, $auth->guardsWithDriver('token'));
        }, shared: true);
        $this->container->factory('auth.driver.token', static fn(ContainerInterface $container): TokenGuardFactory => new TokenGuardFactory(
            $container->get(TokenRepository::class),
        ), shared: true);
        // session.store "database", resolved by the framework's SessionServiceRegistry.
        $this->container->factory('session.store.database', static function (ContainerInterface $container): DatabaseSessionStore {
            $options = $container->get(SessionOptions::class);

            return new DatabaseSessionStore(
                $container->get(ConnectionManager::class)->connection($options->connection),
                $options->table,
                $options->lifetime,
                $container->get(ClockInterface::class),
            );
        }, shared: true);
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
        $commands->register('make:session-table', MakeSessionTableCommand::class, 'Create the migration for the database session table.');
        $commands->register('make:token-table', MakeTokenTableCommand::class, 'Create the migration for the API token table.');
        $commands->register('auth:token', CreateTokenCommand::class, 'Create an API token for a user.');
        $commands->register('auth:token-revoke', RevokeTokenCommand::class, 'Revoke an API token.');
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
