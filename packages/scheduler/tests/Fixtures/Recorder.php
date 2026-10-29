<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Tests\Fixtures;

final class Recorder
{
    /** @var list<string> */
    public array $done = [];
}
