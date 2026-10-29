<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
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
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
                new HandleMessageMiddleware(new HandlersLocator([JobInterface::class => [new JobHandler($container->get(JobRunner::class))]])),
            ]);
        }, shared: true);
        $this->container->factory(QueueInterface::class, static fn(ContainerInterface $container): QueueInterface => new Queue(
            $container->get(MessageBusInterface::class),
            $container->get(Transports::class),
            $container->get(QueueOptions::class)->default,
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
}
