<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\Attribute\Unique;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\QueueInterface;

/** One at a time until it starts. With $again, it queues a copy of itself while it runs. */
#[Unique(for: 60, until: 'processing')]
final class UniqueOnStartJob implements JobInterface
{
    public function __construct(public readonly string $label, public readonly bool $again = false) {}

    public function handle(Recorder $recorder, QueueInterface $queue): void
    {
        $recorder->done[] = $this->label;
        if ($this->again) {
            $queue->dispatch(new self('again'), queue: 'memory');
        }
    }
}
