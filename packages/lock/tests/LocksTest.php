<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock\Tests;

use Crystoline\Xpsr\Lock\LockNotAcquiredException;
use Crystoline\Xpsr\Lock\LockOptions;
use Crystoline\Xpsr\Lock\Locks;
use Crystoline\Xpsr\Lock\Tests\Fixtures\ExpiringMemoryStore;
use Crystoline\Xpsr\Lock\Tests\Fixtures\ReleasingClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class LocksTest extends TestCase
{
    private ExpiringMemoryStore $store;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->store = new ExpiringMemoryStore();
        $this->clock = new MockClock('2026-10-29 12:00:00');
    }

    private function locks(string $prefix = 'app', ?ClockInterface $clock = null): Locks
    {
        return new Locks(new LockFactory($this->store), new LockOptions(prefix: $prefix), $clock ?? $this->clock);
    }

    public function testRunReturnsTheResultAndReleasesTheLock(): void
    {
        $locks = $this->locks();
        self::assertSame('captured', $locks->run('payment:42', static fn(LockInterface $lock): string => $lock->isAcquired() ? 'captured' : 'not held'));

        $next = $locks->lock('payment:42');
        self::assertTrue($next->acquire(), 'Released after run().');
    }

    public function testTheLockIsReleasedWhenTheCallbackThrows(): void
    {
        $locks = $this->locks();
        $thrown = null;
        try {
            $locks->run('payment:42', static function (): string {
                throw new RuntimeException('Gateway timeout');
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }
        self::assertSame('Gateway timeout', $thrown->getMessage(), 'The callback\'s exception reaches the caller.');

        $next = $locks->lock('payment:42');
        self::assertTrue($next->acquire());
    }

    public function testAHeldLockIsNotWaitedForByDefault(): void
    {
        $locks = $this->locks();
        $held = $locks->lock('payment:42');
        self::assertTrue($held->acquire());
        $ran = false;
        try {
            $locks->run('payment:42', static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('run() went ahead without the lock.');
        } catch (LockNotAcquiredException $exception) {
            self::assertSame('payment:42', $exception->key);
            self::assertSame('Could not acquire the lock "payment:42": another process holds it.', $exception->getMessage());
        }
        self::assertFalse($ran);
        self::assertSame('2026-10-29 12:00:00', $this->clock->now()->format('Y-m-d H:i:s'), 'No waiting.');
    }

    public function testRunWaitsUntilTheHolderReleasesTheLock(): void
    {
        $held = $this->locks()->lock('report');
        self::assertTrue($held->acquire());
        $clock = new ReleasingClock($this->clock, $held, afterSleeps: 3);

        self::assertSame('done', $this->locks(clock: $clock)->run('report', static fn(): string => 'done', wait: '5 seconds'));
        self::assertSame(3, $clock->sleeps);
        self::assertLessThan(1.0, $clock->slept, 'Attempts are a fraction of a second apart.');
    }

    public function testWaitingGivesUpAfterTheWait(): void
    {
        $held = $this->locks()->lock('report');
        self::assertTrue($held->acquire());
        $start = (float) $this->clock->now()->format('U.u');
        try {
            $this->locks()->run('report', static fn(): string => 'done', wait: '2 seconds');
            self::fail('run() went ahead without the lock.');
        } catch (LockNotAcquiredException $exception) {
            self::assertSame('Could not acquire the lock "report" within 2 second(s): another process holds it.', $exception->getMessage());
            self::assertSame(2.0, $exception->waited);
        }
        self::assertEqualsWithDelta(2.0, (float) $this->clock->now()->format('U.u') - $start, 0.001, 'It waits the whole time, and no longer.');
    }

    public function testKeysArePrefixed(): void
    {
        $billing = $this->locks('billing')->lock('nightly');
        self::assertTrue($billing->acquire());
        $reports = $this->locks('reports')->lock('nightly');
        self::assertTrue($reports->acquire(), 'Another prefix, so another lock: applications can share a store.');
        $again = $this->locks('billing')->lock('nightly');
        self::assertFalse($again->acquire());
    }

    public function testTheTimeToLiveComesFromTheOptionsOrTheCall(): void
    {
        $default = $this->locks()->lock('default');
        $default->acquire();
        self::assertEqualsWithDelta(300.0, $default->getRemainingLifetime(), 1.0, 'lock.ttl: 5 minutes.');
        $period = $this->locks()->lock('period', '30 seconds');
        $period->acquire();
        self::assertEqualsWithDelta(30.0, $period->getRemainingLifetime(), 1.0);
        $seconds = $this->locks()->lock('seconds', 1.5);
        $seconds->acquire();
        self::assertEqualsWithDelta(1.5, $seconds->getRemainingLifetime(), 0.5);
    }

    public function testTheCallbackCanRefreshTheLockDuringLongWork(): void
    {
        $remaining = $this->locks()->run('import', static function (LockInterface $lock): ?float {
            $lock->refresh(600);

            return $lock->getRemainingLifetime();
        }, ttl: 10);
        self::assertEqualsWithDelta(600.0, $remaining, 1.0);
    }

    public function testPeriodsAreChecked(): void
    {
        try {
            $this->locks()->run('x', static fn(): int => 1, wait: 'soon');
            self::fail('An unreadable wait was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The wait must be zero or more seconds, or a period such as "30 seconds".', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A lock\'s ttl must be more than zero seconds');
        $this->locks()->lock('x', 0);
    }
}
