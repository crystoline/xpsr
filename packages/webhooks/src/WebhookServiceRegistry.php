<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Encryption\Encrypter;
use Crystoline\Xpsr\HttpClient\HttpClientOptions;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Webhooks\Console\MakeWebhooksTableCommand;
use Crystoline\Xpsr\Webhooks\Console\PruneCommand;
use Crystoline\Xpsr\Webhooks\Console\ResendCommand;
use Crystoline\Xpsr\Webhooks\Inbound\InboundSignatures;
use Crystoline\Xpsr\Webhooks\Inbound\VerifyWebhook;
use Crystoline\Xpsr\Webhooks\Inbound\VerifyWebhookMiddleware;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registers webhooks, configured in config/webhooks.php (see WebhookOptions):
 *
 *   Crystoline\Xpsr\Webhooks\WebhooksInterface          send(), sendTo(), resend(). Shared;
 *                                                        Webhooks is the same service.
 *   Crystoline\Xpsr\Webhooks\Endpoints                  the endpoints. Shared.
 *   Crystoline\Xpsr\Webhooks\Deliveries                 the delivery log. Shared.
 *   Crystoline\Xpsr\Webhooks\Inbound\InboundSignatures  verifies providers' webhooks. Shared.
 *   VerifyWebhook::named($name)                         middleware for each webhooks.inbound entry.
 *
 * and the commands make:webhooks-table, webhooks:resend, and webhooks:prune. Needs the database,
 * queue, and HTTP client providers.
 */
final class WebhookServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(WebhookOptions::class, static function (ContainerInterface $container): WebhookOptions {
            $config = $container->get(Configuration::class)->get('webhooks', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The webhooks configuration must be an array.');
            }

            return WebhookOptions::fromArray($config);
        }, shared: true);
        $this->container->factory(UrlGuard::class, static fn(ContainerInterface $container): UrlGuard => new UrlGuard($container->get(WebhookOptions::class)->allowPrivateUrls), shared: true);
        $this->container->factory(Endpoints::class, static function (ContainerInterface $container): Endpoints {
            $options = $container->get(WebhookOptions::class);

            return new Endpoints(
                $container->get(ConnectionManager::class)->connection($options->connection),
                $options->endpointsTable,
                $container->get(Encrypter::class),
                $container->get(UrlGuard::class),
                $container->get(ClockInterface::class),
            );
        }, shared: true);
        $this->container->factory(Deliveries::class, static function (ContainerInterface $container): Deliveries {
            $options = $container->get(WebhookOptions::class);

            return new Deliveries($container->get(ConnectionManager::class)->connection($options->connection), $options->deliveriesTable, $container->get(ClockInterface::class));
        }, shared: true);
        $this->container->factory(Webhooks::class, static function (ContainerInterface $container): Webhooks {
            $options = $container->get(WebhookOptions::class);

            return new Webhooks(
                $container->get(Endpoints::class),
                $container->get(Deliveries::class),
                $container->get(UrlGuard::class),
                $container->get($options->client === null ? ClientInterface::class : HttpClientOptions::serviceId($options->client)),
                $container->get(RequestFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
                $container->get(QueueInterface::class),
                $options,
                $container->get(ClockInterface::class),
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger(),
                $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
            );
        }, shared: true);
        $this->container->alias(WebhooksInterface::class, Webhooks::class);
        $this->container->factory(InboundSignatures::class, static fn(ContainerInterface $container): InboundSignatures => new InboundSignatures(
            $container->get(WebhookOptions::class),
            $container->get(ClockInterface::class),
        ), shared: true);

        // One middleware service per configured verifier: VerifyWebhook::named($name).
        $inbound = $this->container->get(Configuration::class)->get('webhooks.inbound', []);
        foreach (is_array($inbound) ? array_keys($inbound) : [] as $name) {
            if (is_string($name) && preg_match('/\A[a-z0-9_-]+\z/', $name)) {
                $this->container->factory(VerifyWebhook::named($name), static fn(ContainerInterface $container): VerifyWebhookMiddleware => new VerifyWebhookMiddleware($name, $container->get(InboundSignatures::class)), shared: true);
            }
        }

        $this->container->bind(MakeWebhooksTableCommand::class);
        $this->container->bind(ResendCommand::class);
        $this->container->bind(PruneCommand::class);
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('make:webhooks-table', MakeWebhooksTableCommand::class, 'Create the migration for the webhook endpoints and deliveries tables.');
        $commands->register('webhooks:resend', ResendCommand::class, 'Send a webhook delivery again.');
        $commands->register('webhooks:prune', PruneCommand::class, 'Remove old webhook deliveries from the log.');
    }
}
