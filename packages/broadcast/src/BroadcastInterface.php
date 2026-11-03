<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

/**
 * Publishes updates to the browsers subscribed to their topics:
 *
 *     $broadcast->publish('orders/42', ['status' => 'paid']);
 *     $broadcast->publish(['orders/42', 'users/7/orders'], ['status' => 'paid'], private: true, type: 'order.paid');
 *     $broadcast->publishUpdate($event->toUpdate());
 *
 * Inside a database transaction, the update leaves once the transaction commits, and never if it
 * rolls back. With the queue, a job publishes it. A hub that cannot be reached throws
 * HubException: from publish(), from the commit, or, through the queue, after the job's retries.
 */
interface BroadcastInterface
{
    /**
     * @param string|array<array-key, mixed> $topics a topic, or a list of them
     * @param mixed $data a string sent as it is, or anything else encoded as JSON
     * @param bool $private only for subscribers authorized for one of the topics
     * @param string|null $type the event name browsers listen for; "message" without one
     * @param string|null $id the update's ID; the hub assigns one without it
     * @param int|null $retry milliseconds browsers wait before reconnecting
     */
    public function publish(string|array $topics, mixed $data, bool $private = false, ?string $type = null, ?string $id = null, ?int $retry = null): void;

    public function publishUpdate(Update $update): void;
}
