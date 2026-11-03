<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Health\Checks\CacheCheck;
use Crystoline\Xpsr\Health\Checks\DatabaseCheck;
use Crystoline\Xpsr\Health\Checks\DiskSpaceCheck;
use Crystoline\Xpsr\Health\Checks\HttpCheck;
use Crystoline\Xpsr\Health\Checks\LocksCheck;
use Crystoline\Xpsr\Health\Checks\QueueCheck;
use Crystoline\Xpsr\Health\Checks\SchedulerCheck;
use Crystoline\Xpsr\Health\Checks\WorkersCheck;
use Crystoline\Xpsr\Health\Console\HealthCommand;
use Crystoline\Xpsr\Health\Http\HealthController;
use Crystoline\Xpsr\Health\Scheduler\Heartbeat;
use Crystoline\Xpsr\Lock\Locks;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Scheduler\Schedule;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Registers the health checks, configured in config/health.php (see HealthOptions):
 *
 *   Crystoline\Xpsr\Health\Health                 check(): the Report. Shared.
 *   Crystoline\Xpsr\Health\Http\HealthController  live() and ready(), for a route file.
 *   Crystoline\Xpsr\Health\Exposure               who sees a report's details. Shared.
 *
 * the health command, and the built-in checks for what is installed and registered: database
 * (crystoline/xpsr-database), cache (crystoline/xpsr-cache), queue and workers
 * (crystoline/xpsr-queue, the workers with the cache), scheduler (crystoline/xpsr-scheduler
 * with the cache; a heartbeat task joins the schedule), locks (crystoline/xpsr-lock), disk, and
 * http (health.http through crystoline/xpsr-http-client). List it after those providers.
 */
final class HealthServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(HealthOptions::class, static function (ContainerInterface $container): HealthOptions {
            $config = $container->get(Configuration::class)->get('health', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The health configuration must be an array.');
            }

            return HealthOptions::from($config, env('HEALTH_SECRET'));
        }, shared: true);
        $this->container->factory(Exposure::class, static function (ContainerInterface $container): Exposure {
            $proxies = $container->get(Configuration::class)->get('app.trusted_proxies', []);

            return new Exposure($container->get(HealthOptions::class)->secret, is_array($proxies) ? array_values(array_filter($proxies, 'is_string')) : []);
        }, shared: true);
        $this->container->factory(Health::class, static function (ContainerInterface $container): Health {
            $options = $container->get(HealthOptions::class);
            $cache = self::hasCache($container) ? $container->get(CacheInterface::class) : null;

            return new Health(
                [...self::builtIn($container, $options), ...$options->checks],
                $options->skip,
                $container,
                $container->get(ClockInterface::class),
                $cache,
                $options->cache,
                $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
            );
        }, shared: true);

        $this->container->factory(DatabaseCheck::class, static function (ContainerInterface $container): DatabaseCheck {
            $connections = $container->get(HealthOptions::class)->connections;
            if ($connections === null) {
                $configured = $container->get(Configuration::class)->get('database.connections', []);
                $connections = array_values(array_filter(array_map('strval', array_keys(is_array($configured) ? $configured : [])), static fn(string $name): bool => $name !== ''));
            }

            return new DatabaseCheck($container->get(ConnectionManager::class), $connections);
        }, shared: true);
        $this->container->factory(CacheCheck::class, static fn(ContainerInterface $container): CacheCheck => new CacheCheck($container->get(CacheInterface::class)), shared: true);
        $this->container->factory(QueueCheck::class, static function (ContainerInterface $container): QueueCheck {
            $failed = null;
            if ($container->get(\Crystoline\Xpsr\Queue\QueueOptions::class)->failed !== null) {
                $failed = $container->get(FailedJobStore::class);
            }

            return new QueueCheck($container->get(Transports::class), $failed, $container->get(HealthOptions::class)->failedJobs);
        }, shared: true);
        $this->container->factory(WorkersCheck::class, static fn(ContainerInterface $container): WorkersCheck => new WorkersCheck(
            $container->get(CacheItemPoolInterface::class),
            $container->get(Transports::class),
            $container->get(ClockInterface::class),
            $container->get(HealthOptions::class)->workerStale,
        ), shared: true);
        $this->container->factory(SchedulerCheck::class, static fn(ContainerInterface $container): SchedulerCheck => new SchedulerCheck(
            $container->get(CacheItemPoolInterface::class),
            $container->get(ClockInterface::class),
            $container->get(HealthOptions::class)->scheduleStale,
        ), shared: true);
        $this->container->factory(LocksCheck::class, static fn(ContainerInterface $container): LocksCheck => new LocksCheck($container->get(Locks::class)), shared: true);
        $this->container->factory(DiskSpaceCheck::class, static function (ContainerInterface $container): DiskSpaceCheck {
            $options = $container->get(HealthOptions::class);

            return new DiskSpaceCheck($options->diskPath ?? $container->get(Application::class)->getRootDir(), $options->diskWarning, $options->diskFailing);
        }, shared: true);
        $this->container->factory(HttpCheck::class, static fn(ContainerInterface $container): HttpCheck => new HttpCheck(
            $container->get(ClientInterface::class),
            $container->get(RequestFactoryInterface::class),
            $container->get(HealthOptions::class)->http,
        ), shared: true);
        $this->container->bind(HealthController::class);
        $this->container->bind(HealthCommand::class);

        // The heartbeat task, added when the schedule is built rather than at boot.
        if (self::hasScheduler($this->container) && self::hasCachePool($this->container)) {
            $this->container->extend(Schedule::class, static function (mixed $schedule, ContainerInterface $container): mixed {
                if ($schedule instanceof Schedule && $container->get(HealthOptions::class)->runs('scheduler')) {
                    $schedule->call(new Heartbeat(), Heartbeat::NAME)->everyMinute();
                }

                return $schedule;
            });
        }
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('health', HealthCommand::class, 'Run the health checks.');
        }
    }

    /** The built-in checks for what is installed and registered, in the order they report. @return list<string> */
    private static function builtIn(ContainerInterface $container, HealthOptions $options): array
    {
        $checks = [];
        if ($options->runs('database') && self::installed(ConnectionManager::class) && $container->has(MigratorFactoryInterface::class)) {
            $checks[] = DatabaseCheck::class;
        }
        if ($options->runs('cache') && self::hasCache($container)) {
            $checks[] = CacheCheck::class;
        }
        $queue = self::installed(Transports::class) && $container->has(QueueInterface::class);
        if ($options->runs('queue') && $queue) {
            $checks[] = QueueCheck::class;
        }
        if ($options->runs('workers') && $queue && self::hasCachePool($container)) {
            $checks[] = WorkersCheck::class;
        }
        if ($options->runs('scheduler') && self::hasScheduler($container) && self::hasCachePool($container)) {
            $checks[] = SchedulerCheck::class;
        }
        if ($options->runs('locks') && self::installed(Locks::class) && $container->has(PersistingStoreInterface::class)) {
            $checks[] = LocksCheck::class;
        }
        if ($options->runs('disk')) {
            $checks[] = DiskSpaceCheck::class;
        }
        if ($options->runs('http') && $options->http !== [] && interface_exists(ClientInterface::class) && $container->has(ClientInterface::class)) {
            $checks[] = HttpCheck::class;
        }

        return $checks;
    }

    private static function hasCache(ContainerInterface $container): bool
    {
        return interface_exists(CacheInterface::class) && $container->has(CacheInterface::class);
    }

    private static function hasCachePool(ContainerInterface $container): bool
    {
        return interface_exists(CacheItemPoolInterface::class) && $container->has(CacheItemPoolInterface::class);
    }

    /** The scheduler registers its own lock factory under this ID; Schedule itself is a class, which has() always knows. */
    private static function hasScheduler(ContainerInterface $container): bool
    {
        return self::installed(Schedule::class) && class_exists(LockFactory::class) && $container->has(LockFactory::class . '.scheduler');
    }

    /** Whether an optional package's class exists; a function, so analysis does not fold it. */
    private static function installed(string $class): bool
    {
        return class_exists($class);
    }
}
