<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Database\ConnectionManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The behavior every ORM adapter must have: it works on XPSR's connection, so ORM writes and
 * plain DBAL queries share one transaction, and its state can be reset between requests.
 *
 * Each test starts with an empty table "orm_contract_records" (columns "id" integer primary key,
 * "label" string) on the default connection. Map an entity or model to it in the adapter's
 * tests and implement the hooks.
 */
abstract class OrmAdapterContractTestCase extends TestCase
{
    /** XPSR's connection manager, from an application booted with the adapter. */
    abstract protected function connections(): ConnectionManager;

    /** Save a record with this id and label through the ORM, and write it to the database. */
    abstract protected function persist(int $id, string $label): void;

    /** The record's label as the ORM loads it, or null when the ORM finds no record. */
    abstract protected function find(int $id): ?string;

    /** What the adapter does between requests, such as clearing an identity map. */
    abstract protected function reset(): void;

    protected function setUp(): void
    {
        $db = $this->db();
        $schema = $db->createSchemaManager();
        if ($schema->tableExists('orm_contract_records')) {
            $schema->dropTable('orm_contract_records');
        }
        $db->executeStatement('CREATE TABLE orm_contract_records (id INTEGER NOT NULL, label VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
    }

    public function testOrmWritesGoThroughTheSharedConnectionAndTransaction(): void
    {
        $db = $this->db();
        $db->beginTransaction();
        $this->persist(1, 'written by the ORM');
        self::assertSame('written by the ORM', $db->fetchOne('SELECT label FROM orm_contract_records WHERE id = 1'), 'DBAL sees the write inside the same transaction.');
        $db->rollBack();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM orm_contract_records'), 'Rolling back XPSR\'s transaction undoes the ORM write.');
    }

    public function testTheOrmReadsWhatPlainQueriesWrote(): void
    {
        $this->db()->insert('orm_contract_records', ['id' => 2, 'label' => 'written by DBAL']);
        self::assertSame('written by DBAL', $this->find(2));
        self::assertNull($this->find(3));
    }

    public function testResettingDropsStateFromThePreviousRequest(): void
    {
        $this->persist(4, 'before');
        self::assertSame('before', $this->find(4));
        $this->db()->update('orm_contract_records', ['label' => 'after'], ['id' => 4]);
        $this->reset();
        self::assertSame('after', $this->find(4), 'After a reset the ORM loads current data, not objects from the previous request.');
    }

    private function db(): Connection
    {
        return $this->connections()->connection();
    }
}
