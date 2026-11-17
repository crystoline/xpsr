<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use Crystoline\Xpsr\Database\ConnectionManager;
use Doctrine\DBAL\Connection;

/**
 * One run of seeders: a transaction on each connection the run touches, begun when the
 * connection is first used and ended together, committed or rolled back.
 *
 * @internal
 */
final class Run
{
    /** @var array<string, array{Connection, int}> the connection and its nesting level inside the run's transaction */
    private array $begun = [];

    /** Faker seeds PHP's own generator (mt_rand), which the whole process shares. */
    public bool $seededPhpRandom = false;

    public function __construct(
        private readonly ConnectionManager $connections,
        public readonly bool $dryRun,
    ) {}

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->connections->defaultName();
        if (!isset($this->begun[$name])) {
            $connection = $this->connections->connection($name);
            $connection->beginTransaction();
            $this->begun[$name] = [$connection, $connection->getTransactionNestingLevel()];
        }

        return $this->begun[$name][0];
    }

    /** Commits what the run wrote when $keep is true, and rolls it back otherwise. */
    public function finish(bool $keep): void
    {
        if ($this->seededPhpRandom) {
            // What runs after the seeders gets unpredictable numbers again.
            mt_srand();
            $this->seededPhpRandom = false;
        }
        $begun = array_reverse($this->begun);
        $this->begun = [];
        foreach ($begun as [$connection, $level]) {
            // A transaction a seeder left open is not the run's to commit.
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
            if ($connection->getTransactionNestingLevel() < $level) {
                continue;
            }
            $keep ? $connection->commit() : $connection->rollBack();
        }
    }
}
