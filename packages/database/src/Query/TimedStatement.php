<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Query;

use Closure;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/** @internal Part of QueryTiming. */
final class TimedStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $parameters = [];

    /** @param Closure(string, array<int|string, mixed>, float): void $report */
    public function __construct(Statement $statement, private readonly string $sql, private readonly Closure $report)
    {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->parameters[$param] = $value;
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $started = hrtime(true);
        $result = parent::execute();
        ($this->report)($this->sql, $this->parameters, (hrtime(true) - $started) / 1e9);

        return $result;
    }
}
