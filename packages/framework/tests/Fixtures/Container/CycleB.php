<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class CycleB
{
    public function __construct(public CycleA $dependency) {}
}
