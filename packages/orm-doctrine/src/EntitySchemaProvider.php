<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine;

use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/** The schema the mapped entities need, for make:migration --diff. */
final class EntitySchemaProvider implements SchemaProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ?string $connection = null) {}

    public function connection(): ?string
    {
        return $this->connection;
    }

    public function schema(): Schema
    {
        return (new SchemaTool($this->entityManager))->getSchemaFromMetadata($this->entityManager->getMetadataFactory()->getAllMetadata());
    }
}
