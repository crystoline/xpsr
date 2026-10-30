<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Notifications\Channel\Channels;
use Crystoline\Xpsr\Notifications\Channel\DatabaseChannel;
use Crystoline\Xpsr\Notifications\Channel\MailChannel;
use Crystoline\Xpsr\Notifications\Channel\SmsChannel;
use Crystoline\Xpsr\Notifications\Channel\WebPushChannel;
use Crystoline\Xpsr\Notifications\Console\MakeNotificationCommand;
use Crystoline\Xpsr\Notifications\Console\MakeNotificationsTableCommand;
use Crystoline\Xpsr\Notifications\Console\MakePushSubscriptionsTableCommand;
use Crystoline\Xpsr\Notifications\Console\WebPushKeysCommand;
use Crystoline\Xpsr\Notifications\Sms\ArrayTransport;
use Crystoline\Xpsr\Notifications\Sms\LogTransport;
use Crystoline\Xpsr\Notifications\Sms\XpsrTransportFactory;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Http\Discovery\Exception\NotFoundException;
use InvalidArgumentException;
use LogicException;
use Minishlink\WebPush\WebPush;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Texter;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Notifier\Transport;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Registers notifications, configured in config/notifications.php (see NotificationOptions):
 *
 *   Crystoline\Xpsr\Notifications\NotifierInterface    send() and queue(). Shared.
 *   Crystoline\Xpsr\Notifications\Notifier             the same.
 *   Crystoline\Xpsr\Notifications\Inbox                the database channel's notifications, with
 *                                                      crystoline/xpsr-database. Shared.
 *   Crystoline\Xpsr\Notifications\PushSubscriptions    the browsers subscribed to web push, with
 *                                                      crystoline/xpsr-database. Shared.
 *   Symfony\Component\Notifier\TexterInterface         the SMS provider notifications.sms.dsn names,
 *                                                      for SMS outside notifications. Shared.
 *   Crystoline\Xpsr\Notifications\Sms\ArrayTransport   the SMS sent through array://, for tests
 *
 * and the make:notification and webpush:keys commands; with crystoline/xpsr-database,
 * make:notifications-table and make:push-subscriptions-table.
 *
 * The mail channel needs Crystoline\Xpsr\Mail\MailServiceRegistry, and queue() needs
 * Crystoline\Xpsr\Queue\QueueServiceRegistry. SMS providers and web push send through the
 * application's HTTP client when Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry is listed.
 */
final class NotificationServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(NotificationOptions::class, static function (ContainerInterface $container): NotificationOptions {
            $settings = $container->get(Configuration::class);
            $config = $settings->get('notifications', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The notifications configuration must be an array.');
            }
            $url = $settings->get('app.url');

            return NotificationOptions::fromArray($config, is_string($url) ? $url : null);
        }, shared: true);
        $this->container->singleton(ArrayTransport::class);
        $this->container->factory(LogTransport::class, static fn(ContainerInterface $container): LogTransport => new LogTransport(self::logger($container)), shared: true);
        $this->container->factory(TexterInterface::class, static fn(ContainerInterface $container): TexterInterface => new Texter(self::smsTransport($container)), shared: true);
        $this->container->factory(WebPush::class, static fn(ContainerInterface $container): WebPush => self::webPush($container), shared: true);
        $this->container->factory(Channels::class, static fn(ContainerInterface $container): Channels => self::channels($container), shared: true);
        $this->container->factory(Notifier::class, static fn(ContainerInterface $container): Notifier => new Notifier(
            $container->get(Channels::class),
            $container->has(QueueInterface::class) ? static fn(): QueueInterface => $container->get(QueueInterface::class) : null,
            $container->get(NotificationOptions::class)->queue,
            self::logger($container),
            $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
        ), shared: true);
        $this->container->alias(NotifierInterface::class, Notifier::class);
        $this->container->bind(MakeNotificationCommand::class);
        $this->container->bind(WebPushKeysCommand::class);
        if (self::hasDatabase()) {
            $this->container->factory(Inbox::class, static function (ContainerInterface $container): Inbox {
                $options = $container->get(NotificationOptions::class);

                return new Inbox($container->get(ConnectionManager::class)->connection($options->connection), $container->get(ClockInterface::class), $options->table);
            }, shared: true);
            $this->container->factory(PushSubscriptions::class, static function (ContainerInterface $container): PushSubscriptions {
                $options = $container->get(NotificationOptions::class);

                return new PushSubscriptions(
                    $container->get(ConnectionManager::class)->connection($options->pushConnection),
                    $container->get(ClockInterface::class),
                    $options->pushTable,
                    $options->hosts,
                    $options->publicKey,
                );
            }, shared: true);
            $this->container->bind(MakeNotificationsTableCommand::class);
            $this->container->bind(MakePushSubscriptionsTableCommand::class);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('make:notification', MakeNotificationCommand::class, 'Create a notification class.');
        $commands->register('webpush:keys', WebPushKeysCommand::class, 'Make a VAPID key pair for web push.');
        if (self::hasDatabase()) {
            $commands->register('make:notifications-table', MakeNotificationsTableCommand::class, 'Create the migration for the notifications table.');
            $commands->register('make:push-subscriptions-table', MakePushSubscriptionsTableCommand::class, 'Create the migration for the push subscriptions table.');
        }
    }

    private static function channels(ContainerInterface $container): Channels
    {
        $factories = [
            'mail' => static fn(): ChannelInterface => new MailChannel(static function () use ($container): Mailer {
                if (!$container->has(MailerInterface::class)) {
                    throw new LogicException('The mail channel needs crystoline/xpsr-mail: run composer require crystoline/xpsr-mail, and add Crystoline\Xpsr\Mail\MailServiceRegistry to app.registry.');
                }

                return $container->get(Mailer::class);
            }),
            'sms' => static fn(): ChannelInterface => new SmsChannel(static fn(): TexterInterface => $container->get(TexterInterface::class)),
            'database' => static fn(): ChannelInterface => new DatabaseChannel(static fn(): Inbox => self::database($container, Inbox::class, 'database channel')),
            'webpush' => static fn(): ChannelInterface => new WebPushChannel(
                static fn(): WebPush => $container->get(WebPush::class),
                static fn(): PushSubscriptions => self::database($container, PushSubscriptions::class, 'webpush channel'),
                self::logger($container),
            ),
        ];
        foreach ($container->get(NotificationOptions::class)->channels as $name => $class) {
            $factories[$name] = static function () use ($container, $name, $class): ChannelInterface {
                $channel = $container->get($class);
                if (!$channel instanceof ChannelInterface) {
                    throw new InvalidArgumentException("notifications.channels.{$name}: {$class} must implement Crystoline\\Xpsr\\Notifications\\ChannelInterface.");
                }

                return $channel;
            };
        }

        return new Channels($factories);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $service
     * @return T
     */
    private static function database(ContainerInterface $container, string $service, string $channel): object
    {
        if (!self::hasDatabase()) {
            throw new LogicException("The {$channel} needs crystoline/xpsr-database: run composer require crystoline/xpsr-database, and add Crystoline\\Xpsr\\Database\\DatabaseServiceRegistry to app.registry.");
        }
        $instance = $container->get($service);
        if (!$instance instanceof $service) {
            throw new LogicException("{$service} is not available.");
        }

        return $instance;
    }

    /** The SMS transport notifications.sms.dsn names, with XPSR's log:// and array://. */
    private static function smsTransport(ContainerInterface $container): TransportInterface
    {
        $options = $container->get(NotificationOptions::class);
        $http = $container->has(HttpClientInterface::class) ? $container->get(HttpClientInterface::class) : null;
        $factories = [new XpsrTransportFactory($container->get(LogTransport::class), $container->get(ArrayTransport::class))];
        foreach ($options->smsFactories as $class) {
            $factory = $container->get($class);
            if (!$factory instanceof TransportFactoryInterface) {
                throw new InvalidArgumentException("notifications.sms.factories: {$class} must implement Symfony\\Component\\Notifier\\Transport\\TransportFactoryInterface.");
            }
            $factories[] = $factory;
        }
        // Symfony's own factories, one for each installed bridge, given the application's HTTP client.
        $defaults = (new ReflectionMethod(Transport::class, 'getDefaultFactories'))->invoke(null, null, $http);
        foreach (is_iterable($defaults) ? $defaults : [] as $factory) {
            if ($factory instanceof TransportFactoryInterface) {
                $factories[] = $factory;
            }
        }
        try {
            return (new Transport($factories))->fromString($options->smsDsn);
        } catch (UnsupportedSchemeException $exception) {
            throw new InvalidArgumentException("notifications.sms.dsn: {$exception->getMessage()} XPSR adds log:// and array://.", previous: $exception);
        }
    }

    private static function webPush(ContainerInterface $container): WebPush
    {
        if (!class_exists(WebPush::class)) {
            throw new LogicException('The webpush channel needs minishlink/web-push: run composer require minishlink/web-push.');
        }
        $options = $container->get(NotificationOptions::class);
        if ($options->publicKey === null || $options->privateKey === null) {
            throw new LogicException('The webpush channel needs VAPID keys in notifications.webpush.public_key and private_key (VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY): php xpsr webpush:keys --write makes a pair.');
        }
        if ($options->subject === null) {
            throw new LogicException('Set notifications.webpush.subject to a mailto: address or an https:// URL, where push services can reach you.');
        }
        $client = $container->has(ClientInterface::class) ? $container->get(ClientInterface::class) : null;
        $requests = $client instanceof RequestFactoryInterface ? $client : ($container->has(RequestFactoryInterface::class) ? $container->get(RequestFactoryInterface::class) : null);
        $streams = $client instanceof StreamFactoryInterface ? $client : ($container->has(StreamFactoryInterface::class) ? $container->get(StreamFactoryInterface::class) : null);
        try {
            return new WebPush(
                ['VAPID' => ['subject' => $options->subject, 'publicKey' => $options->publicKey, 'privateKey' => $options->privateKey]],
                [],
                $client,
                $requests,
                $streams,
                null,
                self::logger($container),
            );
        } catch (NotFoundException $exception) {
            throw new LogicException('The webpush channel needs a PSR-18 HTTP client: add Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry to app.registry (crystoline/xpsr-http-client), or install guzzlehttp/guzzle.', previous: $exception);
        }
    }

    private static function logger(ContainerInterface $container): LoggerInterface
    {
        return $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger();
    }

    /** Whether crystoline/xpsr-database is installed; a function, so static analysis does not fold it. */
    private static function hasDatabase(): bool
    {
        return class_exists(ConnectionManager::class);
    }
}
