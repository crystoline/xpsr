<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Attribute;

use Attribute;

/**
 * Keeps a job from being queued twice: while one is queued or running, dispatching an identical
 * job drops it. Jobs are identical when they are of the same class and their uniqueId() methods,
 * if they have one, return the same value:
 *
 *     #[Unique(for: '1 hour')]
 *     final class SyncWallet implements JobInterface
 *     {
 *         public function __construct(public readonly int $walletId) {}
 *
 *         public function uniqueId(): string
 *         {
 *             return (string) $this->walletId;
 *         }
 *     }
 *
 * The lock is released when the job succeeds, or when it fails for the last time, after its
 * retries. With until: 'processing', it is released when the job starts, so an identical job
 * can be queued while it runs. It lasts at most $for, so a worker that dies cannot hold it for
 * ever. It needs crystoline/xpsr-lock on the Redis, Redlock, or database store, because the
 * worker releases a lock that the process that dispatched the job took.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Unique
{
    /**
     * @param string|int $for seconds, or a period such as "1 hour": the most the lock lasts
     * @param string $until "done" (released when the job succeeds or fails for the last time)
     *                      or "processing" (released when it starts)
     */
    public function __construct(
        public readonly string|int $for,
        public readonly string $until = 'done',
    ) {}
}
