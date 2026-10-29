<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Testing\OrmAdapterContractTestCase;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\ContractRecord;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Project;

/** The Eloquent adapter passes the contract every ORM adapter must pass. */
final class EloquentContractTest extends OrmAdapterContractTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->project->db()->createSchemaManager()->dropTable('orm_contract_records');
        $this->project->remove();
    }

    protected function connections(): ConnectionManager
    {
        return $this->project->application->get(ConnectionManager::class);
    }

    protected function persist(int $id, string $label): void
    {
        ContractRecord::query()->create(['id' => $id, 'label' => $label]);
    }

    protected function find(int $id): ?string
    {
        return ContractRecord::query()->find($id)?->label;
    }

    /** Eloquent keeps no objects between queries: every find() reads the database. */
    protected function reset(): void {}
}
