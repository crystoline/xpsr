<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

/** Sends webhooks. Inject this; tests swap it for Testing\FakeWebhooks. */
interface WebhooksInterface
{
    /**
     * Queues a delivery to every enabled endpoint subscribed to the event, and returns them. The
     * deliveries leave once the database transaction open now, if any, has committed.
     *
     * @param array<array-key, mixed> $data the event's data, sent as JSON under "data"
     * @return list<Delivery>
     */
    public function send(string $event, array $data): array;

    /**
     * Queues a delivery to one endpoint, whether or not it subscribes to the event.
     *
     * @param array<array-key, mixed> $data
     * @throws \InvalidArgumentException for an unknown or disabled endpoint
     */
    public function sendTo(Endpoint|string $endpoint, string $event, array $data): Delivery;

    /**
     * Queues a failed or delivered delivery again, with the same payload.
     *
     * @throws \InvalidArgumentException for an unknown delivery
     */
    public function resend(string $deliveryId): Delivery;
}
