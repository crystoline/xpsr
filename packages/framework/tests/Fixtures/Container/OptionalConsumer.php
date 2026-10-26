<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class OptionalConsumer
{
    public function __construct(public Dependency $dependency, public int $count = 7, public ?MissingDependency $optional = null) {}
}
