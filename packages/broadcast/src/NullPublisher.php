<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

/** The "null" driver: updates go nowhere, for an environment without a hub. */
final class NullPublisher implements PublisherInterface
{
    public function publish(Update $update): ?string
    {
        return null;
    }
}
