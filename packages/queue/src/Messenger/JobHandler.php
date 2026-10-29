<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\JobRunner;

/**
 * The one Messenger handler: every job handles itself.
 *
 * @internal Used by the message bus QueueServiceRegistry builds.
 */
final class JobHandler
{
    public function __construct(private readonly JobRunner $runner) {}

    public function __invoke(JobInterface $job): void
    {
        $this->runner->run($job);
    }
}
