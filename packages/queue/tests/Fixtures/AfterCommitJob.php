<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\Attribute\AfterCommit;
use Crystoline\Xpsr\Queue\JobInterface;

#[AfterCommit]
final class AfterCommitJob implements JobInterface
{
    public function __construct(public readonly string $label) {}

    public function handle(Recorder $recorder): void
    {
        $recorder->done[] = 'after commit ' . $this->label;
    }
}
