<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Event;

use Crystoline\Xpsr\Notifications\Delivery;
use Throwable;

/**
 * A channel could not deliver a notification to one recipient: dispatched through the
 * application's events (PSR-14) on each failed attempt, including attempts a queue worker retries.
 */
final class NotificationFailed
{
    public function __construct(
        public readonly Delivery $delivery,
        public readonly Throwable $error,
    ) {}
}
