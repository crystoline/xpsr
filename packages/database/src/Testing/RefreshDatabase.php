<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use PHPUnit\Framework\Attributes\Before;

/**
 * For application tests: every test starts with a migrated, empty database. Before the first
 * test of the process, every table on the connection is dropped and the migrations run; then
 * each test runs in a transaction that is rolled back afterwards, rows seeded in setUp()
 * included. On an in-memory SQLite database, which is new for every test, the migrations run
 * each time.
 *
 *   use RefreshDatabase;
 *
 * Nothing to call: it runs before setUp(). Override connectionsToRefresh() for connections other
 * than the default one. It refuses to run when app.env is production. For data that other
 * connections or processes must see during the test, use DatabaseTruncation instead.
 */
trait RefreshDatabase
{
    use DatabaseTransactions;

    /** @return list<string> connection names; none for the default connection */
    protected function connectionsToRefresh(): array
    {
        return [];
    }

    #[Before]
    protected function refreshDatabase(): void
    {
        $connections = $this->connectionsToRefresh();
        foreach ($connections === [] ? [null] : $connections as $connection) {
            TestDatabases::refresh($this->application(), $connection);
        }
        $this->beginDatabaseTransaction(...$connections);
    }
}
