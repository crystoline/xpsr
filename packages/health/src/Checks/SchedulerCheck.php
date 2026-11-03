<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Crystoline\Xpsr\Health\Scheduler\Heartbeat;
use DateTimeInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Reads the heartbeat the health:heartbeat task leaves in the cache every minute schedule:run
 * runs. No heartbeat, or one older than health.schedule_stale, fails the check.
 */
final class SchedulerCheck implements CheckInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        private readonly int $stale,
    ) {}

    public function name(): string
    {
        return 'scheduler';
    }

    public function check(): Result
    {
        $seen = Heartbeat::lastSeen($this->cache);
        if ($seen === null) {
            return Result::failing('schedule:run has not reported yet', ['last_run' => null]);
        }
        $now = $this->clock->now();
        $age = $now->getTimestamp() - $seen;
        $details = ['last_run' => $now->setTimestamp($seen)->format(DateTimeInterface::ATOM), 'seconds_ago' => $age];
        if ($age > $this->stale) {
            return Result::failing(sprintf('schedule:run last reported %d seconds ago', $age), $details);
        }

        return Result::ok(sprintf('schedule:run reported %d seconds ago', $age), $details);
    }
}
