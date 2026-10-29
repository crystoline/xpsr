<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Failed;

use Crystoline\Xpsr\Queue\JobInterface;
use DateTimeImmutable;

/** A job that failed on every attempt, as the failed jobs table keeps it. */
final class FailedJob
{
    public function __construct(
        public readonly int $id,
        public readonly string $transport,
        public readonly string $jobClass,
        public readonly ?JobInterface $job,
        public readonly string $error,
        public readonly DateTimeImmutable $failedAt,
    ) {}
}
