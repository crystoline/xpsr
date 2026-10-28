<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class UnionConsumer
{
    public function __construct(public Dependency|\stdClass $dependency) {}
}
