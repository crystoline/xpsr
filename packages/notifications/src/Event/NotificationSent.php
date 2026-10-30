<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Event;

use Crystoline\Xpsr\Notifications\Delivery;

/**
 * A channel delivered a notification to one recipient: dispatched through the application's
 * events (PSR-14), such as for an audit log. A listener that throws is logged, not reported as a
 * failed delivery, so a queued notification is not sent twice.
 */
final class NotificationSent
{
    public function __construct(public readonly Delivery $delivery) {}
}
