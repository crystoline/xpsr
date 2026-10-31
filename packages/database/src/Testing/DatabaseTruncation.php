<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Application;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * For application tests whose data must be seen by other connections or processes, such as a
 * test that runs two workers against one row: the test's changes are committed, and every table
 * but the migrations table is emptied after the test (after tearDown()). Before the first test
 * of the process, the database is dropped and migrated, as RefreshDatabase does.
 *
 *   use DatabaseTruncation;
 *
 * Override tablesToKeep() for tables to leave alone, such as reference data, and
 * connectionsToRefresh() for connections other than the default one. Emptying tables is slower
 * than a rollback, so prefer RefreshDatabase where it works.
 */
trait DatabaseTruncation
{
    abstract protected function application(): Application;

    /** @return list<string> connection names; none for the default connection */
    protected function connectionsToRefresh(): array
    {
        return [];
    }

    /** @return list<string> tables that are not emptied; the migrations table never is */
    protected function tablesToKeep(): array
    {
        return [];
    }

    #[Before]
    protected function refreshDatabaseBeforeTruncation(): void
    {
        $connections = $this->connectionsToRefresh();
        foreach ($connections === [] ? [null] : $connections as $connection) {
            TestDatabases::refresh($this->application(), $connection);
        }
    }

    #[After]
    protected function truncateDatabaseTables(): void
    {
        $connections = $this->connectionsToRefresh();
        foreach ($connections === [] ? [null] : $connections as $connection) {
            TestDatabases::truncate($this->application(), $connection, $this->tablesToKeep());
        }
    }
}
