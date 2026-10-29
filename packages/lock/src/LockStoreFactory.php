<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Component\Lock\Store\StoreFactory;
use Symfony\Component\Lock\Strategy\ConsensusStrategy;

/**
 * Builds the store lock.store names.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class LockStoreFactory
{
    public static function create(LockOptions $options, string $rootDir, ContainerInterface $container): PersistingStoreInterface
    {
        return match ($options->store) {
            'flock' => self::flock($rootDir . '/var/locks'),
            'database' => self::database($options, $container),
            // Redlock: a lock needs more than half of the servers, and a server that fails counts as a no.
            'redlock' => new CombinedStore(array_map(self::redis(...), $options->redlock), new ConsensusStrategy()),
            default => self::dsn($options->store),
        };
    }

    private static function flock(string $directory): FlockStore
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new LogicException("Locks are kept in {$directory}, which could not be created.");
        }

        return new FlockStore($directory);
    }

    private static function database(LockOptions $options, ContainerInterface $container): DoctrineDbalStore
    {
        // has() is true for any class, ConnectionManager too, so ask for an interface that only
        // DatabaseServiceRegistry binds.
        if (!class_exists(ConnectionManager::class) || !$container->has(MigratorFactoryInterface::class)) {
            throw new LogicException('The database lock store needs crystoline/xpsr-database, with DatabaseServiceRegistry in app.registry. Run: composer require crystoline/xpsr-database');
        }
        $connections = $container->get(ConnectionManager::class);
        if (!$connections instanceof ConnectionManager) {
            throw new LogicException('The ' . ConnectionManager::class . ' service must be a ConnectionManager.');
        }

        // A connection of its own, so a lock is visible to other processes at once and a rollback of
        // the application's transaction does not undo it.
        return new DoctrineDbalStore($connections->open($options->connection), ['db_table' => $options->table]);
    }

    private static function redis(string $dsn): RedisStore
    {
        if (!class_exists(RedisAdapter::class)) {
            throw new LogicException('Redis locks need symfony/cache, and the phpredis extension or predis/predis. Run: composer require symfony/cache predis/predis');
        }
        try {
            // Lazy: a server that is down counts as one failed vote when a lock is taken, not now.
            return new RedisStore(RedisAdapter::createConnection($dsn, ['lazy' => true]));
        } catch (\Exception $exception) {
            throw new InvalidArgumentException("lock.redlock: {$exception->getMessage()}", previous: $exception);
        }
    }

    private static function dsn(string $dsn): PersistingStoreInterface
    {
        try {
            return StoreFactory::createStore($dsn);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException("lock.store: {$exception->getMessage()}", previous: $exception);
        }
    }
}
