<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures\Container;

final class UntypedConsumer
{
    public function __construct(public $token) {}
}
