<?php

declare(strict_types=1);

use Crystoline\Xpsr\Broadcast\BroadcastInterface;
use Crystoline\Xpsr\Broadcast\Subscriptions;

if (!function_exists('broadcast')) {
    /**
     * Publishes an update: broadcast('orders/42', ['status' => 'paid']). Inside a transaction, it
     * leaves once the transaction commits.
     *
     * @param string|array<array-key, mixed> $topics a topic, or a list of them
     * @param mixed $data a string sent as it is, or anything else encoded as JSON
     */
    function broadcast(string|array $topics, mixed $data, bool $private = false, ?string $type = null): void
    {
        app()->get(BroadcastInterface::class)->publish($topics, $data, $private, $type);
    }
}

if (!function_exists('hub_url')) {
    /**
     * The URL browsers subscribe at for the topics, for new EventSource(). The signed-in user's
     * authorization cookie goes on the response, unless $authorize is false.
     *
     * @param string|array<array-key, mixed> $topics a topic, or a list of them
     */
    function hub_url(string|array $topics = [], bool $authorize = true): string
    {
        return app()->get(Subscriptions::class)->hubUrl($topics, $authorize);
    }
}
