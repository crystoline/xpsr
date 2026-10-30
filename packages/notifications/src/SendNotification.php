<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use Crystoline\Xpsr\Queue\JobInterface;

/**
 * The queue job Notifier::queue() dispatches for each recipient and channel: it carries the
 * finished message and delivers it from the worker. Needs crystoline/xpsr-queue.
 */
final class SendNotification implements JobInterface
{
    public function __construct(public readonly Delivery $delivery) {}

    public function handle(Notifier $notifier): void
    {
        $notifier->deliver($this->delivery);
    }
}
