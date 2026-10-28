<?php

declare(strict_types=1);

namespace Crystoline\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 dispatcher. Calls each listener in turn, synchronously, and returns the same event
 * object. A stoppable event is checked before every listener, including the first, so an
 * event that arrives already stopped reaches no listener. Exceptions from a listener stop
 * the dispatch and reach the caller unchanged.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly ListenerProviderInterface $listeners) {}

    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                return $event;
            }
            $listener($event);
        }

        return $event;
    }
}
