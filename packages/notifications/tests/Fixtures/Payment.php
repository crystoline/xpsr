<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

/** Stands in for an entity: notifications made from it are queued as finished messages. */
final class Payment
{
    public function __construct(
        public string $reference,
        public int $amount,
    ) {}
}
