<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests\Fixtures;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;

/** A check with the name of a built-in one. */
final class AnotherDiskCheck implements CheckInterface
{
    public function name(): string
    {
        return 'disk';
    }

    public function check(): Result
    {
        return Result::ok();
    }
}
