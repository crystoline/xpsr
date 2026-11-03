<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Testing\OrmAdapterContractTestCase;
use Crystoline\Xpsr\Orm\Cycle\Http\ClearHeapMiddleware;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\ContractEntity\ContractRecord;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Project;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The Cycle adapter passes the contract every ORM adapter must pass. */
final class CycleContractTest extends OrmAdapterContractTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project(['entities' => [__DIR__ . '/Fixtures/ContractEntity']]);
        $this->project->boot();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    protected function connections(): ConnectionManager
    {
        return $this->project->application->get(ConnectionManager::class);
    }

    protected function persist(int $id, string $label): void
    {
        $this->project->em()->persist(new ContractRecord($id, $label))->run();
    }

    protected function find(int $id): ?string
    {
        $record = $this->project->orm()->getRepository(ContractRecord::class)->findByPK($id);

        return $record instanceof ContractRecord ? $record->label : null;
    }

    /** What happens between requests: the adapter's middleware forgets the loaded entities. */
    protected function reset(): void
    {
        $factory = new Psr17Factory();
        $this->project->application->get(ClearHeapMiddleware::class)->process(
            $factory->createServerRequest('GET', '/'),
            new class ($factory) implements RequestHandlerInterface {
                public function __construct(private readonly Psr17Factory $factory) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->factory->createResponse();
                }
            },
        );
    }
}
