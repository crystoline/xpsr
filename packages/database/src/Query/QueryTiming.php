<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Query;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware that times each query that ran and reports it: the SQL, the values bound to
 * it, and the seconds it took.
 *
 * @internal Installed by ConnectionManager.
 */
final class QueryTiming implements Middleware
{
    /** @param Closure(string, array<int|string, mixed>, float): void $report */
    public function __construct(private readonly Closure $report) {}

    public function wrap(Driver $driver): Driver
    {
        return new TimedDriver($driver, $this->report);
    }
}
