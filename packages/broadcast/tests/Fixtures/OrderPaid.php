<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests\Fixtures;

use Crystoline\Xpsr\Broadcast\BroadcastableInterface;
use Crystoline\Xpsr\Broadcast\Update;

/** An event the provider's listener publishes when it is dispatched. */
final class OrderPaid implements BroadcastableInterface
{
    public function __construct(public readonly int $orderId, public readonly int $userId) {}

    public function toUpdate(): Update
    {
        return new Update(["orders/{$this->orderId}", "users/{$this->userId}/orders"], ['order' => $this->orderId, 'status' => 'paid'], private: true, type: 'order.paid');
    }
}
