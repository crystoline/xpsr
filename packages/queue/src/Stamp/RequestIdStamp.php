<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The ID of the request that dispatched a job, from crystoline/xpsr-log-monolog. The worker sets
 * it while the job runs, so the job's log lines share the request's ID. Queued jobs are stored
 * with it, so its class name and properties stay the same across versions.
 */
final class RequestIdStamp implements StampInterface
{
    public function __construct(public readonly string $id) {}
}
