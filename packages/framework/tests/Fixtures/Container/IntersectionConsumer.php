<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class IntersectionConsumer
{
    public function __construct(public \Countable&\Iterator $dependency) {}
}
