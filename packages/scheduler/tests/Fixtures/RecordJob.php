<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;

final class RecordJob implements JobInterface
{
    public function __construct(public readonly string $label) {}

    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = "job {$this->label}";
    }
}
