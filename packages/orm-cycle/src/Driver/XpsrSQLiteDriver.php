<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Driver;

use Cycle\Database\Driver\SQLite\SQLiteDriver;

/** @internal A Cycle SQLite driver on XPSR's DBAL connection. */
final class XpsrSQLiteDriver extends SQLiteDriver
{
    use SharesXpsrConnection;
}
