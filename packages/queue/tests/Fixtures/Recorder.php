<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

/** A service jobs report to. */
final class Recorder
{
    /** @var list<string> */
    public array $done = [];
}
