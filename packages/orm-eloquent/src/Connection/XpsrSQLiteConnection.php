<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Connection;

use Illuminate\Database\SQLiteConnection;

/** @internal An Eloquent SQLite connection on XPSR's DBAL connection. */
final class XpsrSQLiteConnection extends SQLiteConnection
{
    use SharesXpsrTransactions;
}
