<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class FailingConstructor
{
    public function __construct()
    {
        throw new \RuntimeException('Constructor failure');
    }
}
