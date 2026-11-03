<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

/**
 * Sends one update to the hub, now. Hub does it over HTTP; NullPublisher drops updates. Code
 * publishes through BroadcastInterface, which decides when.
 */
interface PublisherInterface
{
    /**
     * @return string|null the ID the hub gave the update, when it reports one
     *
     * @throws HubException when the hub cannot be reached or refuses the update
     */
    public function publish(Update $update): ?string;
}
