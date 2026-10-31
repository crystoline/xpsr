<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Event;

/**
 * Dispatched through the application's PSR-14 dispatcher after each query on a connection of
 * ConnectionManager, whether DBAL or Eloquent ran it. Listen for it in config/events.php, for
 * example to report slow queries:
 *
 *     Crystoline\Xpsr\Database\Event\QueryExecuted::class => [App\Listeners\ReportSlowQueries::class],
 */
final class QueryExecuted
{
    /** @param array<int|string, mixed> $parameters the bound values, by position (from 1) or name */
    public function __construct(
        public readonly string $connection,
        public readonly string $sql,
        public readonly array $parameters,
        public readonly float $seconds,
    ) {}

    public function milliseconds(): float
    {
        return $this->seconds * 1000;
    }
}
