<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Lock\Console\MakeLockTableCommand;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Registers locks, configured in config/lock.php (see LockOptions):
 *
 *   Crystoline\Xpsr\Lock\Locks                     run() and lock(), with lock.prefix. Shared.
 *   Symfony\Component\Lock\LockFactory             the same store, without the prefix. Shared.
 *   Symfony\Component\Lock\PersistingStoreInterface  the store. Shared. Other packages, such as
 *                                                  the scheduler, use these locks when it is bound.
 *
 * and, with crystoline/xpsr-database, the make:lock-table command.
 */
final class LockServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(LockOptions::class, static function (ContainerInterface $container): LockOptions {
            $config = $container->get(Configuration::class)->get('lock', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The lock configuration must be an array.');
            }

            return LockOptions::fromArray($config);
        }, shared: true);
        $this->container->factory(PersistingStoreInterface::class, static fn(ContainerInterface $container): PersistingStoreInterface => LockStoreFactory::create(
            $container->get(LockOptions::class),
            $container->get(Application::class)->getRootDir(),
            $container,
        ), shared: true);
        $this->container->factory(LockFactory::class, static function (ContainerInterface $container): LockFactory {
            $factory = new LockFactory($container->get(PersistingStoreInterface::class));
            $factory->setLogger($container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger());

            return $factory;
        }, shared: true);
        $this->container->factory(Locks::class, static fn(ContainerInterface $container): Locks => new Locks(
            $container->get(LockFactory::class),
            $container->get(LockOptions::class),
            $container->get(ClockInterface::class),
        ), shared: true);
        if (self::hasDatabase()) {
            $this->container->bind(MakeLockTableCommand::class);
        }
    }

    public function boot(): void
    {
        if (self::hasDatabase() && $this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('make:lock-table', MakeLockTableCommand::class, 'Create the migration for the lock table.');
        }
    }

    /** Whether crystoline/xpsr-database is installed; a function, so static analysis does not fold it. */
    private static function hasDatabase(): bool
    {
        return class_exists(TableMigrationCommand::class);
    }
}
