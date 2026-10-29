<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;

final class NeedsScalarJob implements JobInterface
{
    public function handle(int $count): void {}
}
