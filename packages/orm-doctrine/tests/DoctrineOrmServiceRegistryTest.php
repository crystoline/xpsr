<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests;

use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry;
use Crystoline\Xpsr\Orm\Doctrine\EntitySchemaProvider;
use Crystoline\Xpsr\Orm\Doctrine\Http\ClearEntityManagerMiddleware;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\Entity\Note;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\Project;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctrineOrmServiceRegistryTest extends TestCase
{
    /** @var list<Project> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->application->get(ConnectionManager::class)->connection()->close();
            $project->remove();
        }
    }

    public function testTheEntityManagerUsesXpsrsConnectionAndNativeLazyObjects(): void
    {
        $app = $this->project()->boot();
        $em = $app->get(EntityManagerInterface::class);
        self::assertSame($em, $app->get(EntityManager::class));
        self::assertSame($app->get(ConnectionManager::class)->connection(), $em->getConnection());
        self::assertTrue($em->getConfiguration()->isNativeLazyObjectsEnabled());
        self::assertInstanceOf(EntitySchemaProvider::class, $app->get(SchemaProviderInterface::class));
        self::assertNull($app->get(SchemaProviderInterface::class)->connection());
    }

    public function testEntitiesPersistWithUnderscoreColumnNamesByDefault(): void
    {
        $app = $this->project()->boot();
        $em = $app->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        self::assertSame('created_at', $em->getClassMetadata(Note::class)->getColumnName('createdAt'));

        $note = new Note('Hello');
        $em->persist($note);
        $em->flush();
        $em->clear();
        self::assertSame('Hello', $em->find(Note::class, $note->id)?->body);
        self::assertSame('2026-01-01 00:00:00', $em->getConnection()->fetchOne('SELECT created_at FROM notes'));

        $plain = $this->project(['naming' => 'default'])->boot()->get(EntityManagerInterface::class);
        self::assertSame('createdAt', $plain->getClassMetadata(Note::class)->getColumnName('createdAt'));
    }

    public function testTheMetadataCacheIsMemoryInDebugAndTheApplicationCacheOtherwise(): void
    {
        $pool = new ArrayAdapter();
        $debug = $this->project();
        $debug->application->getContainer()->instance(CacheItemPoolInterface::class, $pool);
        self::assertNotSame($pool, $debug->boot()->get(EntityManagerInterface::class)->getConfiguration()->getMetadataCache());

        $production = $this->project(debug: false);
        $production->application->getContainer()->instance(CacheItemPoolInterface::class, $pool);
        self::assertSame($pool, $production->boot()->get(EntityManagerInterface::class)->getConfiguration()->getMetadataCache());

        $withoutCache = $this->project(debug: false)->boot();
        self::assertInstanceOf(ArrayAdapter::class, $withoutCache->get(EntityManagerInterface::class)->getConfiguration()->getMetadataCache());

        $named = $this->project(['metadata_cache' => 'my.pool']);
        $named->application->getContainer()->instance('my.pool', $pool);
        self::assertSame($pool, $named->boot()->get(EntityManagerInterface::class)->getConfiguration()->getMetadataCache());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['entity_paths' => []], 'Unknown option orm.entity_paths. Known options: connection, entities, metadata_cache, naming.'];
        yield 'missing directory' => [['entities' => ['app/Missing']], 'The entity directory app/Missing in orm.entities does not exist.'];
        yield 'entities not a list' => [['entities' => 'app/Entity'], 'orm.entities must be a list of directories.'];
        yield 'naming' => [['naming' => 'snake'], 'orm.naming must be "underscore" or "default".'];
        yield 'connection' => [['connection' => ''], 'orm.connection must name a connection'];
        yield 'cache service' => [['metadata_cache' => ConnectionManager::class], 'orm.metadata_cache "Crystoline\Xpsr\Database\ConnectionManager" must be a PSR-6 CacheItemPoolInterface service.'];
    }

    /** @param array<string, mixed> $orm */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsNameTheSetting(array $orm, string $message): void
    {
        $app = $this->project($orm)->boot();
        try {
            $app->get(EntityManagerInterface::class);
            self::fail('The entity manager was built from invalid options.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testTheDefaultEntityDirectoryIsOptional(): void
    {
        $project = $this->project();
        $project->application->getConfig()->set('orm', []);
        self::assertSame([], $project->boot()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata());

        $again = $this->project();
        mkdir($again->root . '/app/Entity', 0777, true);
        $again->application->getConfig()->set('orm', []);
        self::assertSame([], $again->boot()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata(), 'An empty app/Entity maps nothing.');
    }

    public function testDoctrineCommandsRunUnderTheXpsrConsole(): void
    {
        $app = $this->project()->boot();
        $registered = $app->get(CommandRegistry::class)->all();
        foreach (array_keys(DoctrineOrmServiceRegistry::COMMANDS) as $name) {
            self::assertArrayHasKey($name, $registered);
        }
        self::assertArrayNotHasKey('orm:schema-tool:update', $registered, 'Schema changes go through migrations.');

        $info = new CommandTester($app->get(DoctrineOrmServiceRegistry::COMMANDS['orm:info'][0]));
        self::assertSame(0, $info->execute([]));
        self::assertStringContainsString(Note::class, $info->getDisplay());
    }

    public function testTheMiddlewareClearsTheEntityManagerAfterEachRequest(): void
    {
        $app = $this->project()->boot();
        $em = $app->get(EntityManagerInterface::class);
        $note = new Note('in memory');
        $em->persist($note);
        $factory = new Psr17Factory();
        $handler = new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(204);
            }
        };

        $response = $app->get(ClearEntityManagerMiddleware::class)->process($factory->createServerRequest('GET', '/'), $handler);
        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($em->contains($note));
    }

    /** @param array<string, mixed> $orm */
    private function project(array $orm = [], bool $debug = true): Project
    {
        return $this->projects[] = new Project($orm, debug: $debug);
    }
}
