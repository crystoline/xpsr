<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures;

/** A plain object with a public id. */
final class Thing
{
    public function __construct(public int|string|null $id = null) {}
}
