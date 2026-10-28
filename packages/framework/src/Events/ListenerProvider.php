<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Events;

use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * PSR-14 listener provider. Listeners are registered for an event class or interface and
 * receive every event that is an instance of it, so a listener for a parent class or an
 * interface also hears its subclasses.
 *
 * A listener is one of:
 *
 *   - a service ID, usually a class with an __invoke(object $event) method
 *   - a [service ID, method] pair
 *   - a closure, invokable object, or [object, method] callable
 *
 * Service listeners are resolved from the container each time an event reaches them, and
 * only then, so a listener after a stopped event is never constructed.
 *
 * Higher priorities run first. Listeners with the same priority run in registration order.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var list<array{event: string, listener: string|array{0: string|object, 1: string}|object, priority: int}> */
    private array $definitions = [];

    /** @var array<class-string, list<callable(object): mixed>> */
    private array $cache = [];

    public function __construct(private readonly ?ContainerInterface $container = null) {}

    /**
     * @param string $event the event class or interface
     * @param string|array<array-key, mixed>|object $listener see the class description
     * @param int $priority higher runs first
     */
    public function listen(string $event, string|array|object $listener, int $priority = 0): void
    {
        if (!class_exists($event) && !interface_exists($event)) {
            throw new InvalidArgumentException("Cannot listen for {$event}: no class or interface has that name.");
        }
        $this->definitions[] = ['event' => $event, 'listener' => $this->validate($event, $listener), 'priority' => $priority];
        $this->cache = [];
    }

    /** @return iterable<callable(object): mixed> */
    public function getListenersForEvent(object $event): iterable
    {
        return $this->cache[$event::class] ??= $this->build($event);
    }

    /**
     * Every registration in the order listeners run: by event name, then priority.
     *
     * @return list<array{event: string, listener: string|array{0: string|object, 1: string}|object, priority: int}>
     */
    public function definitions(): array
    {
        $definitions = $this->definitions;
        uksort($definitions, static fn(int $a, int $b): int => [$definitions[$a]['event'], $definitions[$b]['priority'], $a]
            <=> [$definitions[$b]['event'], $definitions[$a]['priority'], $b]);

        return array_values($definitions);
    }

    /** @return list<callable(object): mixed> */
    private function build(object $event): array
    {
        $matching = array_filter($this->definitions, static fn(array $definition): bool => is_a($event, $definition['event']));
        // Stable: equal priorities keep registration order.
        uksort($matching, static fn(int $a, int $b): int => [$matching[$b]['priority'], $a] <=> [$matching[$a]['priority'], $b]);

        return array_map($this->callable(...), array_column($matching, 'listener'));
    }

    /**
     * @param string|array{0: string|object, 1: string}|object $listener
     * @return callable(object): mixed
     */
    private function callable(string|array|object $listener): callable
    {
        if (is_callable($listener) && !is_string($listener) && !(is_array($listener) && is_string($listener[0]))) {
            return $listener;
        }

        return fn(object $event): mixed => $this->resolve($listener)($event);
    }

    /**
     * @param string|array{0: string|object, 1: string} $listener
     * @return callable(object): mixed
     */
    private function resolve(string|array $listener): callable
    {
        $container = $this->container ?? throw new LogicException('Service listeners need a container.');
        $resolved = is_string($listener) ? $container->get($listener) : [$container->get((string) $listener[0]), $listener[1]];
        if (!is_callable($resolved)) {
            throw new LogicException(sprintf(
                'Event listener %s is not callable. %s',
                self::describe($listener),
                is_string($listener) ? 'Give the class an __invoke(object $event) method.' : 'Check that the method exists and is public.',
            ));
        }

        return $resolved;
    }

    /**
     * @param string|array<array-key, mixed>|object $listener
     * @return string|array{0: string|object, 1: string}|object
     */
    private function validate(string $event, string|array|object $listener): string|array|object
    {
        if (is_string($listener)) {
            if ($listener === '') {
                throw new InvalidArgumentException("A listener for {$event} has an empty service ID.");
            }
            $this->requireContainer($event);

            return $listener;
        }
        if (is_array($listener)) {
            if (!array_is_list($listener) || count($listener) !== 2 || !is_string($listener[1])
                || !(is_string($listener[0]) && $listener[0] !== '' || is_object($listener[0]))) {
                throw new InvalidArgumentException("A listener for {$event} must be a service ID, a [service ID, method] pair, or a callable.");
            }
            if (is_object($listener[0]) && !is_callable($listener)) {
                throw new InvalidArgumentException("A listener for {$event} names a method that cannot be called: " . self::describe($listener));
            }
            if (is_string($listener[0])) {
                $this->requireContainer($event);
            }

            return [$listener[0], $listener[1]];
        }
        if (!is_callable($listener)) {
            throw new InvalidArgumentException("A listener for {$event} is a " . $listener::class . ' object without an __invoke() method.');
        }

        return $listener;
    }

    private function requireContainer(string $event): void
    {
        if ($this->container === null) {
            throw new LogicException("A listener for {$event} is a service ID, but this listener provider has no container.");
        }
    }

    /** A short description of a listener, for messages and event:list. */
    public static function describe(mixed $listener): string
    {
        return match (true) {
            is_string($listener) => $listener,
            $listener instanceof \Closure => 'Closure',
            is_array($listener) && isset($listener[0], $listener[1]) => self::describe($listener[0]) . '::' . (is_string($listener[1]) ? $listener[1] : '?'),
            default => get_debug_type($listener),
        };
    }
}
