<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Broadcast\Console\PublishCommand;
use Crystoline\Xpsr\Broadcast\Http\SubscriptionsController;
use Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Database\Transactions;
use Crystoline\Xpsr\Events\ListenerProvider;
use Crystoline\Xpsr\HttpClient\HttpClientOptions;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers broadcasting through a Mercure hub, configured in config/broadcast.php (see
 * BroadcastOptions) and BROADCAST_* variables:
 *
 *   Crystoline\Xpsr\Broadcast\BroadcastInterface              publish(). Shared; Broadcast is the same service.
 *   Crystoline\Xpsr\Broadcast\Hub                             the hub: publish() now, url(). Shared.
 *   Crystoline\Xpsr\Broadcast\Subscriptions                   who may receive private updates. Shared.
 *   Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware    adds the cookie hub_url() asked for.
 *   Crystoline\Xpsr\Broadcast\Http\SubscriptionsController    authorize(), for a route.
 *
 * the broadcast:publish command, the hub_url() function in every view engine, and a listener
 * that publishes every event implementing BroadcastableInterface. Updates go through a queue
 * job when crystoline/xpsr-queue is registered (broadcast.queue), and wait for the open
 * transaction when crystoline/xpsr-database is. Needs the HTTP client provider; list it after
 * the queue, database, auth, and view providers.
 */
final class BroadcastServiceRegistry extends ServiceRegistry
{
    private const ENVIRONMENT = ['BROADCAST_HUB_URL', 'BROADCAST_PUBLIC_HUB_URL', 'BROADCAST_SECRET', 'BROADCAST_DRIVER'];

    public function register(): void
    {
        $this->container->factory(BroadcastOptions::class, static function (ContainerInterface $container): BroadcastOptions {
            $config = $container->get(Configuration::class)->get('broadcast', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The broadcast configuration must be an array.');
            }
            $environment = [];
            foreach (self::ENVIRONMENT as $name) {
                $environment[$name] = env($name);
            }

            return BroadcastOptions::from($config, $environment);
        }, shared: true);
        $this->container->factory(Tokens::class, static fn(ContainerInterface $container): Tokens => new Tokens(
            $container->get(BroadcastOptions::class),
            $container->get(ClockInterface::class),
        ), shared: true);
        $this->container->factory(Hub::class, static function (ContainerInterface $container): Hub {
            $options = $container->get(BroadcastOptions::class);

            return new Hub(
                $options,
                $container->get(Tokens::class),
                $container->get($options->client === null ? ClientInterface::class : HttpClientOptions::serviceId($options->client)),
                $container->get(RequestFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
            );
        }, shared: true);
        $this->container->factory(PublisherInterface::class, static fn(ContainerInterface $container): PublisherInterface => $container->get(BroadcastOptions::class)->driver === 'null'
            ? new NullPublisher()
            : $container->get(Hub::class), shared: true);
        $this->container->factory(Broadcast::class, static function (ContainerInterface $container): Broadcast {
            $options = $container->get(BroadcastOptions::class);
            $queued = $options->queue !== false && self::installed(QueueInterface::class) && $container->has(QueueInterface::class);

            return new Broadcast(
                $container->get(PublisherInterface::class),
                $queued ? $container->get(QueueInterface::class) : null,
                is_string($options->queue) ? $options->queue : null,
                // Transactions is registered only by DatabaseServiceRegistry, as MigratorFactoryInterface is.
                self::installed(Transactions::class) && $container->has(MigratorFactoryInterface::class) ? $container->get(Transactions::class) : null,
            );
        }, shared: true);
        $this->container->alias(BroadcastInterface::class, Broadcast::class);
        $this->container->factory(Subscriptions::class, static fn(ContainerInterface $container): Subscriptions => new Subscriptions(
            $container->get(BroadcastOptions::class),
            $container->get(Tokens::class),
            $container->get(Hub::class),
            $container->has(CurrentIdentity::class) ? $container->get(CurrentIdentity::class) : null,
        ), shared: true);
        $this->container->bind(SubscriptionsMiddleware::class);
        $this->container->bind(SubscriptionsController::class);
        $this->container->bind(BroadcastListener::class);
        $this->container->bind(PublishCommand::class);

        // hub_url() in templates, added when the view handler is built.
        if ($this->container->has(ViewHandlerInterface::class)) {
            $this->container->extend(ViewHandlerInterface::class, static function (mixed $handler, ContainerInterface $container): mixed {
                if ($handler instanceof ViewFunctionsInterface) {
                    $handler->addFunction('hub_url', static fn(string|array $topics = [], bool $authorize = true): string => $container->get(Subscriptions::class)->hubUrl($topics, $authorize));
                }

                return $handler;
            });
        }
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('broadcast:publish', PublishCommand::class, 'Publish an update to the Mercure hub.');
        }
        if ($this->container->has(ListenerProvider::class)) {
            $this->container->get(ListenerProvider::class)->listen(BroadcastableInterface::class, BroadcastListener::class);
        }
    }

    /** Whether an optional package's class exists; a function, so analysis does not fold it. */
    private static function installed(string $class): bool
    {
        return class_exists($class) || interface_exists($class);
    }
}
