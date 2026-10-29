<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockInterface;

/** A mock clock that releases another process's lock after some sleeps, as if its work ended. */
final class ReleasingClock implements ClockInterface
{
    public int $sleeps = 0;
    public float $slept = 0.0;

    public function __construct(
        private readonly MockClock $clock,
        private readonly LockInterface $held,
        private readonly int $afterSleeps,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        $this->clock->sleep($seconds);
        $this->slept += $seconds;
        if (++$this->sleeps === $this->afterSleeps) {
            $this->held->release();
        }
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
