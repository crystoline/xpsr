<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

/**
 * An event that is published to browsers when it is dispatched. The provider listens for every
 * event implementing it and publishes toUpdate() through BroadcastInterface, so the update
 * waits for the open transaction, or goes through the queue, like any other:
 *
 *     final class OrderPaid implements BroadcastableInterface
 *     {
 *         public function __construct(public readonly int $orderId, public readonly int $userId) {}
 *
 *         public function toUpdate(): Update
 *         {
 *             return new Update("users/{$this->userId}/orders", ['order' => $this->orderId, 'status' => 'paid'], private: true, type: 'order.paid');
 *         }
 *     }
 */
interface BroadcastableInterface
{
    public function toUpdate(): Update;
}
