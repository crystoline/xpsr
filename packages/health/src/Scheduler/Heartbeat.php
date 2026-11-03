<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Scheduler;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * The task the package adds to the schedule, every minute, so that the scheduler check can
 * tell whether schedule:run is running: it writes the time to the cache.
 *
 * @internal Added to the Schedule by HealthServiceRegistry.
 */
final class Heartbeat
{
    public const NAME = 'health:heartbeat';
    public const KEY = 'xpsr_health_schedule';

    public function __invoke(CacheItemPoolInterface $cache, ClockInterface $clock): void
    {
        $item = $cache->getItem(self::KEY);
        $item->set($clock->now()->getTimestamp());
        $item->expiresAfter(86400);
        $cache->save($item);
    }

    /** The Unix time of the last run, or null. */
    public static function lastSeen(CacheItemPoolInterface $cache): ?int
    {
        $item = $cache->getItem(self::KEY);
        $value = $item->isHit() ? $item->get() : null;

        return is_int($value) ? $value : null;
    }
}
