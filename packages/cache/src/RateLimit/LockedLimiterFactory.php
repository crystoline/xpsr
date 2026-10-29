<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use Closure;
use DateInterval;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Sliding-window limiters that lock a key's count while they update it, so parallel requests
 * cannot both take the last unit.
 *
 * Symfony's RateLimiterFactory locks through a LockFactory, whose locks last 300 seconds: a
 * process that died holding one would hold up that key's requests for five minutes. This
 * factory takes its locks from a closure, which sets a short lifetime and the lock key.
 *
 * @internal
 */
final class LockedLimiterFactory implements RateLimiterFactoryInterface
{
    /** @param (Closure(string): LockInterface)|null $lock the lock for a limiter ID; null for none */
    public function __construct(
        private readonly string $id,
        private readonly int $limit,
        private readonly DateInterval $interval,
        private readonly StorageInterface $storage,
        private readonly ?Closure $lock,
    ) {}

    public function create(?string $key = null): LimiterInterface
    {
        // The IDs Symfony's factory gives, so counts kept before locking was added still apply.
        $id = "{$this->id}-{$key}";

        return new SlidingWindowLimiter($id, $this->limit, $this->interval, $this->storage, $this->lock === null ? null : ($this->lock)($id));
    }
}
