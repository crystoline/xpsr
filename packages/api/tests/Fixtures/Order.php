<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

/** A plain object with relations that may or may not be loaded. */
final class Order
{
    public ?Customer $customer;
    /** @var list<Order> */
    public array $related;
    public ?string $note = null;

    public function __construct(
        public int $id,
        public string $reference,
        public int $amount,
        public bool $paid = false,
    ) {}
}
