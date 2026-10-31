<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\Attribute\Retry;
use Crystoline\Xpsr\Queue\JobInterface;
use RuntimeException;

/** Fails every time; retried more often than its transport allows. */
#[Retry(maxRetries: 3, delay: 0)]
final class RetriedJob implements JobInterface
{
    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = 'retried attempt';
        throw new RuntimeException('Still broken.');
    }
}
