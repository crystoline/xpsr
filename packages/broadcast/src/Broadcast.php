<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use Crystoline\Xpsr\Database\Transactions;
use Crystoline\Xpsr\Queue\QueueInterface;

/**
 * The broadcast service: with the queue, each update is a PublishUpdate job, which waits for the
 * open transaction to commit; without it, the update is published after the open transaction
 * commits when crystoline/xpsr-database is registered, and at once otherwise.
 */
final class Broadcast implements BroadcastInterface
{
    /** @param string|null $transport the queue transport the jobs go to; the queue's default without one */
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly ?QueueInterface $queue = null,
        private readonly ?string $transport = null,
        private readonly ?Transactions $transactions = null,
    ) {}

    public function publish(string|array $topics, mixed $data, bool $private = false, ?string $type = null, ?string $id = null, ?int $retry = null): void
    {
        $this->publishUpdate(new Update($topics, $data, $private, $type, $id, $retry));
    }

    public function publishUpdate(Update $update): void
    {
        if ($this->queue !== null) {
            $this->queue->dispatch(new PublishUpdate($update), queue: $this->transport);

            return;
        }
        if ($this->transactions !== null) {
            $this->transactions->afterCommit(function () use ($update): void {
                $this->publisher->publish($update);
            });

            return;
        }
        $this->publisher->publish($update);
    }
}
