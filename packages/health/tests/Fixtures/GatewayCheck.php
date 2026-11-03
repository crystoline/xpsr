<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests\Fixtures;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;

/** An application's own check, warning about a low balance. */
final class GatewayCheck implements CheckInterface
{
    public function name(): string
    {
        return 'gateway';
    }

    public function check(): Result
    {
        return Result::warning('The gateway balance is low', ['balance' => 12]);
    }
}
