<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Cycle\Database\DatabaseManager;
use Doctrine\DBAL\Schema\Schema;

/** The tables the entities need, for make:migration --diff: those on Cycle's default database (cycle.connection). */
final class EntitySchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private readonly SchemaBuilder $builder,
        private readonly DatabaseManager $databases,
        private readonly ?string $connection = null,
    ) {}

    public function connection(): ?string
    {
        return $this->connection;
    }

    public function schema(): Schema
    {
        return SchemaConverter::toDbal($this->builder->compile()->tablesOf($this->databases->database()->getName()));
    }
}
