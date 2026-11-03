<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

/** Publishes every dispatched event that implements BroadcastableInterface. */
final class BroadcastListener
{
    public function __construct(private readonly BroadcastInterface $broadcast) {}

    public function __invoke(BroadcastableInterface $event): void
    {
        $this->broadcast->publishUpdate($event->toUpdate());
    }
}
