<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Event;

use Crystoline\Xpsr\Webhooks\Delivery;
use Crystoline\Xpsr\Webhooks\Endpoint;

/** An endpoint accepted a delivery with a 2xx answer. Dispatched through PSR-14 when the event dispatcher is bound. */
final class WebhookDelivered
{
    public function __construct(public readonly Delivery $delivery, public readonly Endpoint $endpoint) {}
}
