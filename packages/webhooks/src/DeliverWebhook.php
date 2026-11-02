<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use Crystoline\Xpsr\Queue\Attribute\AfterCommit;
use Crystoline\Xpsr\Queue\Attribute\Retry;
use Crystoline\Xpsr\Queue\JobInterface;
use Throwable;

/**
 * The queue job that sends one delivery. It leaves once the transaction open when it was
 * dispatched has committed, and is tried again after 1, 4, 16, and 64 minutes and then 6 hours
 * when the endpoint does not answer 2xx; after that, the delivery is recorded as failed.
 */
#[AfterCommit]
#[Retry(maxRetries: 5, delay: '1 minute', multiplier: 4, maxDelay: '6 hours')]
final class DeliverWebhook implements JobInterface
{
    public function __construct(public readonly string $deliveryId) {}

    public function handle(Webhooks $webhooks): void
    {
        $webhooks->deliver($this->deliveryId);
    }

    public function failed(Throwable $error, Webhooks $webhooks): void
    {
        $webhooks->giveUp($this->deliveryId, $error);
    }
}
