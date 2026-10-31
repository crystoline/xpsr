<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Database\Transactions;
use Crystoline\Xpsr\Lock\Locks;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\Queue\Console\MakeJobCommand;
use Crystoline\Xpsr\Queue\Console\MakeQueueTableCommand;
use Crystoline\Xpsr\Queue\Console\QueueFailedCommand;
use Crystoline\Xpsr\Queue\Console\QueueForgetCommand;
use Crystoline\Xpsr\Queue\Console\QueueRestartCommand;
use Crystoline\Xpsr\Queue\Console\QueueRetryCommand;
use Crystoline\Xpsr\Queue\Console\QueueWorkCommand;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\Messenger\DatabaseTransportFactory;
use Crystoline\Xpsr\Queue\Messenger\JobHandler;
use Crystoline\Xpsr\Queue\Messenger\RequestIds;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\Messenger\UniqueJobs;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

/**
 * Registers the queue, configured in config/queue.php:
 *
 *   Crystoline\Xpsr\Queue\QueueInterface        dispatch() jobs
 *   Crystoline\Xpsr\Queue\JobRunner             runs a job's handle() with injected services
 *   Crystoline\Xpsr\Queue\Failed\FailedJobStore the failed jobs table
 *
 * and queue:work, queue:failed, queue:retry, queue:forget, queue:restart, make:job, and
 * make:queue-table. List it in app.registry after Crystoline\Xpsr\Database\DatabaseServiceRegistry.
 *
 * #[Unique] jobs take their locks from crystoline/xpsr-lock, when its LockServiceRegistry is in
 * app.registry. With crystoline/xpsr-log-monolog, jobs carry the ID of the request that
 * dispatched them, and the worker logs with it.
 */
final class QueueServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(QueueOptions::class, static function (ContainerInterface $container): QueueOptions {
            $config = $container->get(Configuration::class)->get('queue', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The queue configuration must be an array.');
            }

            return QueueOptions::fromArray($config);
        }, shared: true);
        $this->container->singleton(JobRunner::class);
        $this->container->factory(UniqueJobs::class, static fn(ContainerInterface $container): UniqueJobs => new UniqueJobs(
            self::hasLocks($container) ? static fn(): Locks => $container->get(Locks::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        ), shared: true);
        $this->container->factory(Transports::class, static fn(ContainerInterface $container): Transports => new Transports(
            $container->get(QueueOptions::class),
            static fn(): MessageBusInterface => $container->get(MessageBusInterface::class),
            // MigratorFactoryInterface is registered only by DatabaseServiceRegistry.
            $container->has(MigratorFactoryInterface::class)
                ? new DatabaseTransportFactory($container->get(ConnectionManager::class), $container->get(QueueOptions::class)->table)
                : null,
        ), shared: true);
        $this->container->factory(MessageBusInterface::class, static function (ContainerInterface $container): MessageBusInterface {
            $options = $container->get(QueueOptions::class);

            return new MessageBus([
                new SendMessageMiddleware(new SendersLocator([JobInterface::class => [$options->default]], $container->get(Transports::class))),
                $container->get(UniqueJobs::class),
                new HandleMessageMiddleware(new HandlersLocator([JobInterface::class => [new JobHandler($container->get(JobRunner::class))]])),
            ]);
        }, shared: true);
        $this->container->factory(QueueInterface::class, static fn(ContainerInterface $container): QueueInterface => new Queue(
            $container->get(MessageBusInterface::class),
            $container->get(Transports::class),
            $container->get(QueueOptions::class)->default,
            $container->get(UniqueJobs::class),
            self::requestIds($container),
            // Transactions is registered only by DatabaseServiceRegistry, as MigratorFactoryInterface is.
            $container->has(MigratorFactoryInterface::class) ? $container->get(Transactions::class) : null,
            $container->get(JobRunner::class),
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        ), shared: true);
        $this->container->factory(FailedJobStore::class, static function (ContainerInterface $container): FailedJobStore {
            $failed = $container->get(QueueOptions::class)->failed
                ?? throw new LogicException('queue.failed is false, so failed jobs are only logged.');
            if (!$container->has(MigratorFactoryInterface::class)) {
                throw new LogicException('The failed jobs table needs Crystoline\Xpsr\Database\DatabaseServiceRegistry in app.registry, or set queue.failed to false.');
            }

            return new FailedJobStore($container->get(ConnectionManager::class)->connection($failed['connection']), $failed['table'], $container->get(ClockInterface::class));
        }, shared: true);

        $this->container->factory(QueueWorkCommand::class, static fn(ContainerInterface $container): QueueWorkCommand => new QueueWorkCommand(
            $container->get(Transports::class),
            $container->get(MessageBusInterface::class),
            $container->get(QueueOptions::class)->default,
            $container->get(QueueOptions::class)->failed === null ? null : $container->get(FailedJobStore::class),
            $container->has(CacheItemPoolInterface::class) ? $container->get(CacheItemPoolInterface::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            array_values(array_filter([$container->get(UniqueJobs::class), self::requestIds($container)])),
            $container->get(JobRunner::class),
        ));
        $this->container->factory(QueueRestartCommand::class, static fn(ContainerInterface $container): QueueRestartCommand => new QueueRestartCommand(
            $container->has(CacheItemPoolInterface::class) ? $container->get(CacheItemPoolInterface::class) : null,
        ));
        foreach ([QueueFailedCommand::class, QueueRetryCommand::class, QueueForgetCommand::class, MakeJobCommand::class, MakeQueueTableCommand::class] as $command) {
            $this->container->bind($command);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('queue:work', QueueWorkCommand::class, 'Run queued jobs.');
        $commands->register('queue:failed', QueueFailedCommand::class, 'List failed jobs.');
        $commands->register('queue:retry', QueueRetryCommand::class, 'Queue failed jobs again.');
        $commands->register('queue:forget', QueueForgetCommand::class, 'Remove failed jobs.');
        $commands->register('queue:restart', QueueRestartCommand::class, 'Tell workers to stop after their current job.');
        $commands->register('make:job', MakeJobCommand::class, 'Create a job class.');
        $commands->register('make:queue-table', MakeQueueTableCommand::class, 'Create the migration for the queue tables.');
    }

    /**
     * Whether crystoline/xpsr-lock is installed and its LockServiceRegistry is in app.registry.
     * has() is true for any class, so ask for the store interface only that registry binds.
     */
    private static function hasLocks(ContainerInterface $container): bool
    {
        return self::installed(Locks::class) && $container->has(PersistingStoreInterface::class);
    }

    /** The request IDs of crystoline/xpsr-log-monolog, or null without it. */
    private static function requestIds(ContainerInterface $container): ?RequestIds
    {
        if (!self::installed(RequestId::class)) {
            return null;
        }
        $requestId = $container->get(RequestId::class);

        return new RequestIds($requestId->get(...), $requestId->set(...));
    }

    /** Whether an optional package's class exists; a function, so analysis does not fold it. */
    private static function installed(string $class): bool
    {
        return class_exists($class);
    }
}
