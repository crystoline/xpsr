<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests\Fixtures;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use RuntimeException;

/** A check that throws instead of answering. */
final class BrokenCheck implements CheckInterface
{
    public function name(): string
    {
        return 'broken';
    }

    public function check(): Result
    {
        throw new RuntimeException('Boom.');
    }
}
