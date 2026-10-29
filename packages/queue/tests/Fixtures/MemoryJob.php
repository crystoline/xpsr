<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\Attribute\OnQueue;
use Crystoline\Xpsr\Queue\JobInterface;

#[OnQueue('memory')]
final class MemoryJob implements JobInterface
{
    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = 'memory';
    }
}
