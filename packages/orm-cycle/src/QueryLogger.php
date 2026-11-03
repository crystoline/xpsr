<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Crystoline\Xpsr\Database\QueryEvents;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Where a Cycle driver's log goes: each executed statement is reported to QueryEvents, so
 * database.log_queries, database.slow_queries, and QueryExecuted listeners see Cycle's queries
 * as they see DBAL's, without the parameter values: Cycle logs those already resolved for its
 * own log. Transaction messages and errors are not reported.
 *
 * @internal
 */
final class QueryLogger extends AbstractLogger
{
    public function __construct(private readonly QueryEvents $events, private readonly string $connection) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Only an executed statement carries the time it took.
        if ($level !== LogLevel::INFO || !isset($context['elapsed'])) {
            return;
        }
        $this->events->executed($this->connection, (string) $message, [], (float) $context['elapsed']);
    }
}
