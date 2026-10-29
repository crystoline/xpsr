<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\QueueInterface;

final class ChainJob implements JobInterface
{
    public function handle(QueueInterface $queue): void
    {
        $queue->dispatch(new RecordJob('chained'));
    }
}
