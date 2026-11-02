<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests\Fixtures;

use RuntimeException;

final class Failing
{
    public static function gatewayDown(): string
    {
        throw new RuntimeException('Gateway down.');
    }
}
