<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;
use RuntimeException;

final class FailingJob implements JobInterface
{
    public function __construct(public readonly string $reason = 'Broken.') {}

    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = 'attempt';
        throw new RuntimeException($this->reason);
    }
}
