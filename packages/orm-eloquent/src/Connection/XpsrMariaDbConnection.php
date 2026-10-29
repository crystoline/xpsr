<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Connection;

use Illuminate\Database\MariaDbConnection;

/** @internal An Eloquent MariaDB connection on XPSR's DBAL connection. */
final class XpsrMariaDbConnection extends MariaDbConnection
{
    use SharesXpsrTransactions;
}
