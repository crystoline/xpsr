<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests\Fixtures;

/** A service jobs and commands report to. */
final class Recorder
{
    /** @var list<string> */
    public array $done = [];
}
