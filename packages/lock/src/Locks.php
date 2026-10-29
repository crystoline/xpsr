<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

/**
 * Locks shared by every process and server that uses the same store (config/lock.php):
 *
 *     $receipt = $locks->run("payment:{$id}", fn() => $payments->capture($id), ttl: '30 seconds', wait: '5 seconds');
 *
 * A lock that expires can be taken by another process while its holder is stalled past the
 * expiry, for example by a slow network call. Use locks to avoid doing work twice, and keep
 * correctness in the database: a unique idempotency key, or SELECT ... FOR UPDATE in a
 * transaction.
 */
final class Locks
{
    /** Seconds between attempts while waiting, before jitter. */
    private const RETRY_DELAY = 0.05;

    public function __construct(
        private readonly LockFactory $factory,
        private readonly LockOptions $options,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The lock on $key, not acquired yet: acquire(), refresh(), release(), and isAcquired().
     *
     * @param int|float|string|null $ttl how long it lasts once acquired unless refreshed, in
     *                                   seconds or a period such as "30 seconds"; null for lock.ttl
     * @param bool $autoRelease release the lock when the object is destroyed
     */
    public function lock(string $key, int|float|string|null $ttl = null, bool $autoRelease = true): LockInterface
    {
        return $this->factory->createLock(
            $this->options->prefix . ':' . $key,
            $ttl === null ? $this->options->ttl : LockOptions::seconds($ttl, 'A lock\'s ttl'),
            $autoRelease,
        );
    }

    /**
     * Runs $callback while holding the lock on $key, then releases it, also when $callback
     * throws. Waits up to $wait for another process to release it, then throws
     * LockNotAcquiredException. $callback receives the lock, to refresh() it during long work.
     *
     * @template T
     * @param callable(LockInterface): T $callback
     * @param int|float|string|null $ttl as for lock()
     * @param int|float|string $wait seconds or a period such as "5 seconds"; 0 tries once
     * @return T
     * @throws LockNotAcquiredException
     */
    public function run(string $key, callable $callback, int|float|string|null $ttl = null, int|float|string $wait = 0): mixed
    {
        $lock = $this->lock($key, $ttl);
        $this->acquire($lock, $key, LockOptions::seconds($wait, 'The wait', zero: true));
        try {
            $result = $callback($lock);
        } catch (Throwable $exception) {
            try {
                $lock->release();
            } catch (Throwable) {
                // The callback's exception matters more; the lock expires or ends with the process.
            }
            throw $exception;
        }
        $lock->release();

        return $result;
    }

    private function acquire(LockInterface $lock, string $key, float $wait): void
    {
        $deadline = $this->now() + $wait;
        while (!$lock->acquire()) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                throw new LockNotAcquiredException($key, $wait);
            }
            // Jitter keeps processes waiting for the same lock from retrying in step.
            $this->clock->sleep(min($remaining, self::RETRY_DELAY * (1 + mt_rand() / mt_getrandmax())));
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
