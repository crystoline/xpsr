<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Query;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** @internal Part of QueryTiming. */
final class TimedDriver extends AbstractDriverMiddleware
{
    /** @param Closure(string, array<int|string, mixed>, float): void $report */
    public function __construct(Driver $driver, private readonly Closure $report)
    {
        parent::__construct($driver);
    }

    /** @param array<string, mixed> $params */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        return new TimedConnection(parent::connect($params), $this->report);
    }
}
