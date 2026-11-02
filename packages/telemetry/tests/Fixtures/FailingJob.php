<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;
use RuntimeException;

final class FailingJob implements JobInterface
{
    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = 'attempt';
        throw new RuntimeException('Broken.');
    }
}
