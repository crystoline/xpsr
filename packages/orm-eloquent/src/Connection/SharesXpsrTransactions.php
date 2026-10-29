<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Connection;

use Closure;
use Doctrine\DBAL\Connection as DbalConnection;
use Throwable;

/**
 * Runs an Eloquent connection's transactions through XPSR's DBAL connection, which owns the PDO
 * they share. DBAL nests them with savepoints, so an Eloquent transaction inside an XPSR one (or
 * the reverse, as in DatabaseTransactions tests) never starts a second PDO transaction, and
 * rolling back the outer one undoes both.
 *
 * @internal Used by the connection classes in this namespace.
 */
trait SharesXpsrTransactions
{
    private DbalConnection $xpsr;

    public function useXpsrConnection(DbalConnection $connection): void
    {
        $this->xpsr = $connection;
    }

    /**
     * @template TReturn
     *
     * @param (Closure(static): TReturn) $callback
     * @param int $attempts
     * @return TReturn
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($attempt = 1; ; $attempt++) {
            $this->beginTransaction();
            try {
                $result = $callback($this);
            } catch (Throwable $exception) {
                $this->rollBack();
                if ($attempt < $attempts && $this->transactions === 0 && $this->causedByConcurrencyError($exception)) {
                    continue;
                }
                throw $exception;
            }
            $this->commit();

            return $result;
        }
    }

    protected function createTransaction()
    {
        $this->xpsr->beginTransaction();
    }

    public function commit()
    {
        if ($this->transactions === 0) {
            return;
        }
        if ($this->transactions === 1) {
            $this->fireConnectionEvent('committing');
        }
        $this->xpsr->commit();
        [$level, $this->transactions] = [$this->transactions, $this->transactions - 1];
        $this->transactionsManager?->commit($this->getName(), $level, $this->transactions);
        $this->fireConnectionEvent('committed');
    }

    protected function performRollBack($toLevel)
    {
        for ($level = $this->transactions; $level > $toLevel; $level--) {
            $this->xpsr->rollBack();
        }
    }
}
