<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class ContainerConsumer
{
    public function __construct(public \Psr\Container\ContainerInterface $container) {}
}
