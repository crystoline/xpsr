<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class CycleA
{
    public function __construct(public CycleB $dependency) {}
}
