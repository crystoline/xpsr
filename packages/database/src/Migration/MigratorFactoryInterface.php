<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Migration;

/**
 * Creates the migration runner for a connection. The runner used by the migrate commands is the
 * service named in database.migrations.runner, Crystoline\Xpsr\Database\MigratorFactory by default.
 */
interface MigratorFactoryInterface
{
    /** @param string|null $connection a name from database.connections; null for the default */
    public function create(?string $connection = null): MigratorInterface;
}
