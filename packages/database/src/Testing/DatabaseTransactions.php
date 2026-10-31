<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Transactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\After;

/**
 * For application tests: run each test inside database transactions that are rolled back
 * afterwards, so nothing a test writes is kept. Use it in a test case that has the skeleton's
 * application() method, and start the transactions once the schema exists:
 *
 *   use DatabaseTransactions;
 *
 *   protected function setUp(): void
 *   {
 *       $this->beginDatabaseTransaction();           // the default connection
 *       // or $this->beginDatabaseTransaction('main', 'reporting');
 *   }
 *
 * Everything that uses XPSR's connections is covered, including code that opens its own
 * transactions (DBAL nests them as savepoints) and ORM adapters, which share the connections.
 * The test's transaction does not count for Transactions::afterCommit(): callbacks, and
 * #[AfterCommit] jobs, run when the code under test commits its own outermost transaction.
 * MySQL and MariaDB commit implicitly on schema changes, so do not change the schema inside the
 * test there; migrate before beginDatabaseTransaction().
 */
trait DatabaseTransactions
{
    /** @var array<string, Connection> by connection name */
    private array $databaseTransactionConnections = [];

    private ?Transactions $databaseTransactionCallbacks = null;

    abstract protected function application(): Application;

    /** Start a transaction on each named connection, or on the default connection. */
    protected function beginDatabaseTransaction(string ...$connections): void
    {
        $application = $this->application();
        $manager = $application->get(ConnectionManager::class);
        $this->databaseTransactionCallbacks = $application->get(Transactions::class);
        foreach ($connections === [] ? [$manager->defaultName()] : $connections as $name) {
            $connection = $manager->connection($name);
            $connection->beginTransaction();
            $this->databaseTransactionCallbacks->ignoreUpTo($name, $connection->getTransactionNestingLevel());
            $this->databaseTransactionConnections[$name] = $connection;
        }
    }

    /** Roll back everything the test left open, including nested transactions. */
    #[After]
    protected function rollBackDatabaseTransactions(): void
    {
        foreach (array_reverse($this->databaseTransactionConnections, true) as $name => $connection) {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->databaseTransactionCallbacks?->ignoreUpTo($name, 0);
        }
        $this->databaseTransactionConnections = [];
    }
}
