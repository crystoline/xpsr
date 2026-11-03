<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use Crystoline\Xpsr\Queue\Attribute\AfterCommit;
use Crystoline\Xpsr\Queue\Attribute\Retry;
use Crystoline\Xpsr\Queue\JobInterface;

/**
 * The queue job that publishes one update. It leaves once the transaction open when it was
 * dispatched has committed, and is tried again after 2, 10, and 50 seconds when the hub does not
 * take it; after that, it is recorded as failed.
 */
#[AfterCommit]
#[Retry(maxRetries: 3, delay: '2 seconds', multiplier: 5, maxDelay: '1 minute')]
final class PublishUpdate implements JobInterface
{
    public function __construct(public readonly Update $update) {}

    public function handle(PublisherInterface $publisher): void
    {
        $publisher->publish($this->update);
    }
}
