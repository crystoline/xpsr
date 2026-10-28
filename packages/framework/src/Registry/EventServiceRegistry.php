<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Console\Commands\EventListCommand;
use Crystoline\Xpsr\Console\Commands\MakeEventCommand;
use Crystoline\Xpsr\Console\Commands\MakeListenerCommand;
use Crystoline\Xpsr\Events\EventDispatcher;
use Crystoline\Xpsr\Events\ListenerProvider;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Registers the PSR-14 event dispatcher, and the event:list, make:event, and make:listener
 * commands when the console is part of the application.
 *
 * Listeners come from config/events.php:
 *
 *   'listeners' => [
 *       App\Events\UserRegistered::class => [App\Listeners\SendWelcomeEmail::class],
 *   ],
 *
 * Providers can add more in boot(), with a priority if needed:
 *
 *   $this->container->get(ListenerProvider::class)->listen(UserRegistered::class, AuditLog::class, priority: 10);
 */
final class EventServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(ListenerProvider::class, static function (ContainerInterface $container): ListenerProvider {
            $provider = new ListenerProvider($container);
            $configured = $container->get(Configuration::class)->get('events.listeners', []);
            if (!is_array($configured)) {
                throw new InvalidArgumentException('events.listeners must map event classes to lists of listeners.');
            }
            foreach ($configured as $event => $listeners) {
                if (!is_string($event) || !is_array($listeners) || !array_is_list($listeners)) {
                    throw new InvalidArgumentException('events.listeners must map event classes to lists of listeners.');
                }
                foreach ($listeners as $listener) {
                    if (!is_string($listener) && !is_array($listener) && !is_object($listener)) {
                        throw new InvalidArgumentException("A listener for {$event} in events.listeners must be a service ID, a [service ID, method] pair, or a callable.");
                    }
                    $provider->listen($event, $listener);
                }
            }

            return $provider;
        }, shared: true);
        $this->container->alias(ListenerProviderInterface::class, ListenerProvider::class);
        $this->container->factory(
            EventDispatcherInterface::class,
            static fn(ContainerInterface $container): EventDispatcher => new EventDispatcher($container->get(ListenerProviderInterface::class)),
            shared: true,
        );
        $this->container->alias(EventDispatcher::class, EventDispatcherInterface::class);
        $this->container->bind(EventListCommand::class);
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $commands = $this->container->get(CommandRegistry::class);
            $commands->register('event:list', EventListCommand::class, 'List event listeners in the order they run.');
            $commands->register('make:event', MakeEventCommand::class, 'Create an event class.');
            $commands->register('make:listener', MakeListenerCommand::class, 'Create an event listener class.');
        }
    }
}
