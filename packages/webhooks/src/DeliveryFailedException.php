<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use RuntimeException;
use Throwable;

/** An attempt to deliver a webhook failed; the queue tries again until the retries run out. */
final class DeliveryFailedException extends RuntimeException
{
    public function __construct(public readonly Delivery $delivery, string $message, ?Throwable $previous = null)
    {
        parent::__construct("Webhook delivery {$delivery->id} ({$delivery->event}) failed on attempt {$delivery->attempts}: {$message}", 0, $previous);
    }
}
