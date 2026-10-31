<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database;

use Doctrine\DBAL\Connection as DbalConnection;
use Throwable;

/**
 * The connections ConnectionManager makes: Doctrine DBAL connections that tell Transactions
 * when their transactions begin, commit, and roll back, so afterCommit() callbacks run once the
 * outermost transaction has committed.
 */
class Connection extends DbalConnection
{
    private ?Transactions $transactions = null;
    private string $name = '';
    private bool $callbackFailed = false;

    /** @internal Set by ConnectionManager on shared connections. */
    public function reportTransactionsTo(Transactions $transactions, string $name): void
    {
        $this->transactions = $transactions;
        $this->name = $name;
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->callbackFailed = false;
        $this->transactions?->begun($this->name, $this->getTransactionNestingLevel());
    }

    public function commit(): void
    {
        $level = $this->getTransactionNestingLevel();
        $transactions = $this->transactions;
        if ($transactions === null || $level === 0) {
            parent::commit();

            return;
        }
        // Taken before the commit: with auto-commit off, DBAL begins the next transaction inside commit().
        $callbacks = $transactions->committing($this->name, $level);
        parent::commit();
        if ($callbacks === []) {
            return;
        }
        try {
            $transactions->run($callbacks);
        } catch (Throwable $exception) {
            // transactional() rolls back when commit() throws, and there is no transaction left to roll back.
            $this->callbackFailed = $this->getTransactionNestingLevel() === 0;
            throw $exception;
        }
    }

    public function rollBack(): void
    {
        if ($this->callbackFailed) {
            $this->callbackFailed = false;
            if ($this->getTransactionNestingLevel() === 0) {
                return;
            }
        }
        $level = $this->getTransactionNestingLevel();
        try {
            parent::rollBack();
        } finally {
            if ($level > 0) {
                $this->transactions?->rolledBack($this->name, $level);
            }
        }
    }
}
