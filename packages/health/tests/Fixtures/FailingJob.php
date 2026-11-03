<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;
use RuntimeException;

final class FailingJob implements JobInterface
{
    public function handle(): void
    {
        throw new RuntimeException('Broken.');
    }
}
