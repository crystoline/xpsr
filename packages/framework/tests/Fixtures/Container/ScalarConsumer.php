<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class ScalarConsumer
{
    public function __construct(public string $token) {}
}
