<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
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
 * MySQL and MariaDB commit implicitly on schema changes, so do not change the schema inside the
 * test there; migrate before beginDatabaseTransaction().
 */
trait DatabaseTransactions
{
    /** @var list<Connection> */
    private array $databaseTransactionConnections = [];

    abstract protected function application(): Application;

    /** Start a transaction on each named connection, or on the default connection. */
    protected function beginDatabaseTransaction(string ...$connections): void
    {
        $manager = $this->application()->get(ConnectionManager::class);
        foreach ($connections === [] ? [null] : $connections as $name) {
            $connection = $manager->connection($name);
            $connection->beginTransaction();
            $this->databaseTransactionConnections[] = $connection;
        }
    }

    /** Roll back everything the test left open, including nested transactions. */
    #[After]
    protected function rollBackDatabaseTransactions(): void
    {
        foreach (array_reverse($this->databaseTransactionConnections) as $connection) {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        $this->databaseTransactionConnections = [];
    }
}
