<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

use DateTimeImmutable;
use DateTimeInterface;

/** The results of one run of the checks, with the time of the run. Cached as is. */
final class Report
{
    /** @param array<string, Result> $results by check name, in the order the checks ran */
    public function __construct(
        public readonly array $results,
        public readonly DateTimeImmutable $checkedAt,
    ) {}

    /** The worst status among the checks; ok with no checks. */
    public function status(): Status
    {
        $status = Status::Ok;
        foreach ($this->results as $result) {
            $status = $status->worse($result->status);
        }

        return $status;
    }

    public function isFailing(): bool
    {
        return $this->status() === Status::Failing;
    }

    public function result(string $name): ?Result
    {
        return $this->results[$name] ?? null;
    }

    /**
     * @param bool $details with each check's message, details, and time, or statuses alone
     * @return array{status: string, checked_at: string, checks: array<string, array<string, mixed>>}
     */
    public function toArray(bool $details): array
    {
        $checks = [];
        foreach ($this->results as $name => $result) {
            $checks[$name] = $result->toArray($details);
        }

        return ['status' => $this->status()->value, 'checked_at' => $this->checkedAt->format(DateTimeInterface::ATOM), 'checks' => $checks];
    }
}
