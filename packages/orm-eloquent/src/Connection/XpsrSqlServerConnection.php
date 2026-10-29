<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Connection;

use Illuminate\Database\SqlServerConnection;

/** @internal An Eloquent SQL Server connection on XPSR's DBAL connection. */
final class XpsrSqlServerConnection extends SqlServerConnection
{
    use SharesXpsrTransactions;
}
