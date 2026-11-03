<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Driver;

use Cycle\Database\Driver\SQLServer\SQLServerDriver;

/** @internal A Cycle SQL Server driver on XPSR's DBAL connection. */
final class XpsrSQLServerDriver extends SQLServerDriver
{
    use SharesXpsrConnection;
}
