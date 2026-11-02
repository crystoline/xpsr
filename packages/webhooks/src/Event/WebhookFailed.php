<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Event;

use Crystoline\Xpsr\Webhooks\Delivery;
use Crystoline\Xpsr\Webhooks\Endpoint;

/**
 * A delivery was given up on: the retries ran out, the endpoint answered 410 Gone, or the
 * endpoint was removed, disabled, or had a URL webhooks may not be sent to. Dispatched through
 * PSR-14 when the event dispatcher is bound.
 */
final class WebhookFailed
{
    public function __construct(public readonly Delivery $delivery, public readonly ?Endpoint $endpoint, public readonly string $error) {}
}
