<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\Attribute\Retry;
use Crystoline\Xpsr\Queue\JobInterface;

#[Retry(maxRetries: -1)]
final class BadRetryJob implements JobInterface
{
    public function handle(): void {}
}
