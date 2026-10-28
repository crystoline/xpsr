<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Testing\OrmAdapterContractTestCase;
use Crystoline\Xpsr\Orm\Doctrine\Http\ClearEntityManagerMiddleware;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\ContractEntity\ContractRecord;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\Project;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The Doctrine adapter passes the contract every ORM adapter must pass. */
final class DoctrineOrmContractTest extends OrmAdapterContractTestCase
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
        $this->connections()->connection()->close();
        $this->project->remove();
    }

    protected function connections(): ConnectionManager
    {
        return $this->project->application->get(ConnectionManager::class);
    }

    protected function persist(int $id, string $label): void
    {
        $em = $this->entityManager();
        $em->persist(new ContractRecord($id, $label));
        $em->flush();
    }

    protected function find(int $id): ?string
    {
        return $this->entityManager()->find(ContractRecord::class, $id)?->label;
    }

    /** What happens between requests: the adapter's middleware clears the entity manager. */
    protected function reset(): void
    {
        $factory = new Psr17Factory();
        $this->project->application->get(ClearEntityManagerMiddleware::class)->process(
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

    private function entityManager(): EntityManagerInterface
    {
        return $this->project->application->get(EntityManagerInterface::class);
    }
}
