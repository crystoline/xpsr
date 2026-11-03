<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Driver;

use Cycle\Database\Driver\Postgres\PostgresDriver;

/** @internal A Cycle PostgreSQL driver on XPSR's DBAL connection. */
final class XpsrPostgresDriver extends PostgresDriver
{
    use SharesXpsrConnection;
}
