<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Scheduler\Console\ScheduleListCommand;
use Crystoline\Xpsr\Scheduler\Console\ScheduleRunCommand;
use Crystoline\Xpsr\Scheduler\Console\ScheduleWorkCommand;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface as SymfonyClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\StoreFactory;

/**
 * Registers the scheduler:
 *
 *   Crystoline\Xpsr\Scheduler\Schedule   the tasks from routes/schedule.php (scheduler.files)
 *
 * and schedule:run, schedule:list, and schedule:work. Tasks that queue jobs also need
 * Crystoline\Xpsr\Queue\QueueServiceRegistry.
 */
final class SchedulerServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(SchedulerOptions::class, static function (ContainerInterface $container): SchedulerOptions {
            $config = $container->get(Configuration::class);
            $scheduler = $config->get('scheduler', []);
            if (!is_array($scheduler)) {
                throw new InvalidArgumentException('The scheduler configuration must be an array.');
            }
            $timezone = $config->get('app.timezone', 'UTC');

            return SchedulerOptions::fromArray($scheduler, $container->get(Application::class)->getRootDir(), is_string($timezone) ? $timezone : 'UTC');
        }, shared: true);
        $this->container->factory(Schedule::class, static function (ContainerInterface $container): Schedule {
            $options = $container->get(SchedulerOptions::class);
            $schedule = new Schedule($options->timezone);
            foreach ($options->files as $file) {
                $define = (static fn(string $file): mixed => require $file)($file);
                if (!is_callable($define)) {
                    throw new LogicException("{$file} must return a function that takes the Schedule: return static function (Schedule \$schedule): void { ... };");
                }
                $define($schedule);
            }

            return $schedule;
        }, shared: true);
        $this->container->factory(LockFactory::class . '.scheduler', static function (ContainerInterface $container): LockFactory {
            $lock = $container->get(SchedulerOptions::class)->lock;
            // Without scheduler.lock, the application's locks (crystoline/xpsr-lock), when its registry
            // binds the store. The store is checked because has() is true for any class, LockFactory too.
            if ($lock === null && $container->has(PersistingStoreInterface::class)) {
                $shared = $container->get(LockFactory::class);

                return $shared instanceof LockFactory ? $shared : throw new LogicException('The ' . LockFactory::class . ' service must be a LockFactory.');
            }
            $lock ??= 'flock';
            if ($lock === 'flock') {
                $directory = $container->get(Application::class)->getRootDir() . '/var/locks';
                if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new LogicException("The scheduler keeps its locks in {$directory}, which could not be created.");
                }
                $store = new FlockStore($directory);
            } else {
                try {
                    $store = StoreFactory::createStore($lock);
                } catch (\InvalidArgumentException $exception) {
                    throw new InvalidArgumentException("scheduler.lock: {$exception->getMessage()}", previous: $exception);
                }
            }
            $locks = new LockFactory($store);
            $locks->setLogger($container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger());

            return $locks;
        }, shared: true);
        $this->container->factory(TaskRunner::class, static fn(ContainerInterface $container): TaskRunner => new TaskRunner(
            $container,
            $container->get(LockFactory::class . '.scheduler'),
        ), shared: true);

        $this->container->factory(ScheduleRunCommand::class, static fn(ContainerInterface $container): ScheduleRunCommand => new ScheduleRunCommand(
            $container->get(Schedule::class),
            $container->get(TaskRunner::class),
            $container->get(SymfonyClockInterface::class),
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger(),
        ));
        $this->container->factory(ScheduleListCommand::class, static fn(ContainerInterface $container): ScheduleListCommand => new ScheduleListCommand(
            $container->get(Schedule::class),
            $container->get(ClockInterface::class),
        ));
        $this->container->factory(ScheduleWorkCommand::class, static fn(ContainerInterface $container): ScheduleWorkCommand => new ScheduleWorkCommand(
            $container->get(SymfonyClockInterface::class),
            $container->get(Application::class)->getRootDir() . '/xpsr',
        ));
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('schedule:run', ScheduleRunCommand::class, 'Run the scheduled tasks that are due.');
        $commands->register('schedule:list', ScheduleListCommand::class, 'List the scheduled tasks.');
        $commands->register('schedule:work', ScheduleWorkCommand::class, 'Run due scheduled tasks every minute, without cron.');
    }
}
