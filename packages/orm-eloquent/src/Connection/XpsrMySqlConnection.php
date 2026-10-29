<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Connection;

use Illuminate\Database\MySqlConnection;

/** @internal An Eloquent MySQL connection on XPSR's DBAL connection. */
final class XpsrMySqlConnection extends MySqlConnection
{
    use SharesXpsrTransactions;
}
