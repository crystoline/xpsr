<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class CycleA
{
    public function __construct(public CycleB $dependency) {}
}
