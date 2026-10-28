<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;

/**
 * The SQL that turns the database into an expected schema, and back.
 *
 * Only tables and sequences named in the expected schema are compared, so tables it does not
 * describe, such as the migrations table or tables managed by hand, are never dropped. Removing
 * a table therefore needs a hand-written migration. The SQL is for the connection's database
 * platform.
 */
final class SchemaDiff
{
    /**
     * @param list<string> $up statements that apply the expected schema
     * @param list<string> $down statements that undo them
     */
    private function __construct(public readonly array $up, public readonly array $down) {}

    public static function between(Connection $connection, Schema $expected): self
    {
        $schemaManager = $connection->createSchemaManager();
        $current = $schemaManager->introspectSchema();
        $known = new Schema(
            array_values(array_filter($current->getTables(), static fn(Table $table): bool => $expected->hasTable($table->getObjectName()->toString()))),
            array_values(array_filter($current->getSequences(), static fn(Sequence $sequence): bool => $expected->hasSequence($sequence->getObjectName()->toString()))),
        );
        $comparator = $schemaManager->createComparator();
        $platform = $connection->getDatabasePlatform();

        return new self(
            $platform->getAlterSchemaSQL($comparator->compareSchemas($known, $expected)),
            $platform->getAlterSchemaSQL($comparator->compareSchemas($expected, $known)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->up === [];
    }
}
