<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Cycle\Database\Schema\AbstractTable;

/**
 * The schema compiled from the entities, and the tables they describe, by database.
 *
 * @internal
 */
final class CompiledSchema
{
    /**
     * @param array<string, array<int, mixed>> $schema the ORM schema, by role
     * @param array<string, list<AbstractTable>> $tables the tables the entities declare, by database name
     */
    public function __construct(public readonly array $schema, public readonly array $tables) {}

    /** @return list<AbstractTable> */
    public function tablesOf(string $database): array
    {
        return $this->tables[$database] ?? [];
    }
}
