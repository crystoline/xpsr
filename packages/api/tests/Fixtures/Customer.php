<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

final class Customer
{
    public function __construct(public int $id, public string $name) {}
}
