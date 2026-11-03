<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Driver;

use Cycle\Database\Driver\PDOInterface;
use Doctrine\DBAL\Connection as DbalConnection;
use LogicException;
use PDO;

/**
 * Makes a Cycle driver use XPSR's DBAL connection: the PDO it opened, and its transactions,
 * which DBAL nests with savepoints. A Cycle transaction inside an XPSR one (or the reverse, as
 * in DatabaseTransactions tests) never starts a second PDO transaction, rolling back the outer
 * one undoes both, and Transactions::afterCommit() sees Cycle's commits.
 *
 * @internal Used by the driver classes in this namespace.
 */
trait SharesXpsrConnection
{
    private ?DbalConnection $xpsr = null;

    public function useXpsrConnection(DbalConnection $connection): void
    {
        $this->xpsr = $connection;
    }

    protected function createPDO(): PDO|PDOInterface
    {
        $native = $this->xpsr()->getNativeConnection();

        return $native instanceof PDO
            ? $native
            : throw new LogicException('Cycle needs a PDO driver; the connection uses ' . get_debug_type($native) . '. Use pdo_mysql, pdo_pgsql, pdo_sqlite, or pdo_sqlsrv.');
    }

    public function beginTransaction(?string $isolationLevel = null): bool
    {
        $connection = $this->xpsr();
        if ($isolationLevel !== null && $connection->getTransactionNestingLevel() === 0) {
            $this->setIsolationLevel($isolationLevel);
        }
        $connection->beginTransaction();

        return true;
    }

    public function commitTransaction(): bool
    {
        $this->xpsr()->commit();

        return true;
    }

    public function rollbackTransaction(): bool
    {
        $this->xpsr()->rollBack();

        return true;
    }

    public function getTransactionLevel(): int
    {
        return $this->xpsr()->getTransactionNestingLevel();
    }

    private function xpsr(): DbalConnection
    {
        return $this->xpsr ?? throw new LogicException('The Cycle driver has no XPSR connection yet; Databases gives it one.');
    }
}
