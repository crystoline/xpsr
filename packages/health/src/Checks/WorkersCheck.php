<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\WorkerHeartbeat;
use DateTimeInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Reads the heartbeat queue:work leaves in the cache for each transport it works on. A
 * transport with no heartbeat, or one older than health.worker_stale, fails the check.
 */
final class WorkersCheck implements CheckInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly Transports $transports,
        private readonly ClockInterface $clock,
        private readonly int $stale,
    ) {}

    public function name(): string
    {
        return 'workers';
    }

    public function check(): Result
    {
        $now = $this->clock->now();
        $details = [];
        $failing = [];
        foreach ($this->transports->names() as $name) {
            if ($this->transports->isSync($name)) {
                continue;
            }
            $seen = WorkerHeartbeat::lastSeen($this->cache, $name);
            if ($seen === null) {
                $details[$name] = ['last_seen' => null];
                $failing[] = sprintf('no worker has reported on "%s"', $name);
                continue;
            }
            $age = $now->getTimestamp() - $seen;
            $details[$name] = ['last_seen' => $now->setTimestamp($seen)->format(DateTimeInterface::ATOM), 'seconds_ago' => $age];
            if ($age > $this->stale) {
                $failing[] = sprintf('the worker on "%s" was last seen %d seconds ago', $name, $age);
            }
        }
        if ($details === []) {
            return Result::ok('No transport needs a worker');
        }
        if ($failing !== []) {
            return Result::failing(ucfirst(implode('; ', $failing)), $details);
        }

        return Result::ok(count($details) === 1 ? 'The worker is reporting' : 'The workers are reporting', $details);
    }
}
