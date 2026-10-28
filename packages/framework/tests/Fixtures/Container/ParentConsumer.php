<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class ParentConsumer extends ParentDependency
{
    public function __construct(public parent $dependency) {}
}
