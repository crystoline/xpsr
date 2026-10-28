<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class VariadicConsumer
{
    public array $dependencies;
    public function __construct(Dependency ...$dependencies)
    {
        $this->dependencies = $dependencies;
    }
}
