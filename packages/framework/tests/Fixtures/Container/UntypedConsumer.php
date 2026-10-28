<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class UntypedConsumer
{
    public function __construct(public $token) {}
}
