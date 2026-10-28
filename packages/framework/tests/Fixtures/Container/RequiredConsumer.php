<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class RequiredConsumer
{
    public function __construct(public MissingDependency $dependency) {}
}
