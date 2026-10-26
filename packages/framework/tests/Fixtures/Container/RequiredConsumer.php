<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class RequiredConsumer
{
    public function __construct(public MissingDependency $dependency) {}
}
