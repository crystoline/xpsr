<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Throwable;

/** Runs the platform's dummy query (SELECT 1) on each connection; one that fails fails the check. */
final class DatabaseCheck implements CheckInterface
{
    /** @param list<string> $connections names from database.connections */
    public function __construct(private readonly ConnectionManager $connections_, private readonly array $connections) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): Result
    {
        $details = [];
        foreach ($this->connections as $name) {
            $start = hrtime(true);
            try {
                $connection = $this->connections_->connection($name);
                $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL())->fetchOne();
            } catch (Throwable $exception) {
                $details[$name] = ['error' => $exception->getMessage()];

                return Result::failing(sprintf('Connection "%s": %s', $name, $exception->getMessage()), $details);
            }
            $details[$name] = ['milliseconds' => round((hrtime(true) - $start) / 1_000_000, 2)];
        }

        return Result::ok(count($details) === 1 ? 'Connected' : sprintf('%d connections answer', count($details)), $details);
    }
}
