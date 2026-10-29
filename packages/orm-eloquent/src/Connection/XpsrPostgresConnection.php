<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Connection;

use Illuminate\Database\PostgresConnection;

/** @internal An Eloquent PostgreSQL connection on XPSR's DBAL connection. */
final class XpsrPostgresConnection extends PostgresConnection
{
    use SharesXpsrTransactions;
}
