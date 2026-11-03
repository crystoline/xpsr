<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Driver;

use Cycle\Database\Driver\MySQL\MySQLDriver;

/** @internal A Cycle MySQL and MariaDB driver on XPSR's DBAL connection. */
final class XpsrMySQLDriver extends MySQLDriver
{
    use SharesXpsrConnection;
}
