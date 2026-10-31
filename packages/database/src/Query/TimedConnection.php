<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Query;

use Closure;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal Part of QueryTiming. */
final class TimedConnection extends AbstractConnectionMiddleware
{
    /** @param Closure(string, array<int|string, mixed>, float): void $report */
    public function __construct(Connection $connection, private readonly Closure $report)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TimedStatement(parent::prepare($sql), $sql, $this->report);
    }

    public function query(string $sql): Result
    {
        $started = hrtime(true);
        $result = parent::query($sql);
        ($this->report)($sql, [], (hrtime(true) - $started) / 1e9);

        return $result;
    }

    public function exec(string $sql): int|string
    {
        $started = hrtime(true);
        $count = parent::exec($sql);
        ($this->report)($sql, [], (hrtime(true) - $started) / 1e9);

        return $count;
    }
}
