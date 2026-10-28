<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * Describes the schema the application expects, for example from ORM entity mapping. With a
 * provider bound under this interface, make:migration --diff compares the database with it and
 * writes a migration for the difference.
 */
interface SchemaProviderInterface
{
    /** The connection the schema belongs to, from database.connections; null for the default. */
    public function connection(): ?string;

    /** The tables (and sequences) the application expects. Tables it does not list are left alone. */
    public function schema(): Schema;
}
