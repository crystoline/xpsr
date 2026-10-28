<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Container;

final class InvokableService
{
    public int $calls = 0;
    public function __invoke(): string
    {
        ++$this->calls;
        return 'called';
    }
}
