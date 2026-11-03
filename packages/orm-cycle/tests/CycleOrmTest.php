<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests;

use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\Event\QueryExecuted;
use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Crystoline\Xpsr\Database\Transactions;
use Crystoline\Xpsr\Orm\Cycle\Console\MakeEntityCommand;
use Crystoline\Xpsr\Orm\Cycle\Console\SchemaCacheCommand;
use Crystoline\Xpsr\Orm\Cycle\Console\SchemaClearCommand;
use Crystoline\Xpsr\Orm\Cycle\Console\SchemaCommand;
use Crystoline\Xpsr\Orm\Cycle\CycleOptions;
use Crystoline\Xpsr\Orm\Cycle\EntitySchemaProvider;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity\Note;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity\Notebook;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity\NoteRepository;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Project;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use ArrayObject;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Cycle on XPSR's connection: entities, repositories, transactions, query events, the schema cache, and the commands. */
final class CycleOrmTest extends TestCase
{
    private Project $project;

    /** @var list<QueryExecuted> */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->project = new Project();
        $config = $this->project->application->getConfig();
        $config->set('app.registry', [EventServiceRegistry::class, ...(array) $config->get('app.registry')]);
        $config->set('events.listeners', [QueryExecuted::class => [function (QueryExecuted $event): void {
            $this->queries[] = $event;
        }]]);
        $this->project->boot();
        $this->project->migrateEntities();
        $this->queries = [];
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheServicesAreRegistered(): void
    {
        $app = $this->project->application;
        self::assertInstanceOf(ORMInterface::class, $app->get(ORMInterface::class));
        self::assertSame($app->get(ORMInterface::class), $app->get(ORMInterface::class), 'Shared.');
        self::assertInstanceOf(EntityManagerInterface::class, $app->get(EntityManagerInterface::class));
        self::assertSame('main', $app->get(DatabaseManager::class)->database()->getName(), 'The default database is the default XPSR connection.');
        self::assertInstanceOf(EntitySchemaProvider::class, $app->get(SchemaProviderInterface::class));
        self::assertTrue($app->get(SchemaInterface::class)->defines('note'));
        foreach (['cycle:schema', 'cycle:schema:cache', 'cycle:schema:clear', 'make:entity'] as $command) {
            self::assertArrayHasKey($command, $app->get(CommandRegistry::class)->all());
        }
    }

    public function testEntitiesAreSavedAndLoadedWithTheirRelationsAndBehaviors(): void
    {
        $notebook = new Notebook('Ideas');
        $first = new Note('Write the docs');
        $first->pinned = true;
        $notebook->add($first);
        $notebook->add(new Note('Ship it'));
        $this->project->em()->persist($notebook)->run();
        self::assertNotNull($notebook->id);
        self::assertInstanceOf(DateTimeImmutable::class, $first->createdAt, 'The CreatedAt behavior filled the timestamp.');

        $repository = $this->project->orm()->getRepository(Note::class);
        self::assertInstanceOf(NoteRepository::class, $repository, 'The entity names its repository.');
        self::assertSame($this->project->application->getConfig(), $repository->configuration, 'Built from the container, with its dependency.');
        $pinned = $repository->pinned();
        self::assertCount(1, $pinned);
        self::assertSame('Write the docs', $pinned[0]->body);

        $loaded = (new Select($this->project->orm(), Notebook::class))->load('notes')->fetchOne(['id' => $notebook->id]);
        self::assertInstanceOf(Notebook::class, $loaded);
        self::assertSame($notebook, $loaded, 'The same object, from the heap.');
        self::assertCount(2, $loaded->notes);
        self::assertSame(2, (int) $this->project->db()->fetchOne('SELECT COUNT(*) FROM notes WHERE notebook_id = ?', [$notebook->id]), 'Plain queries see the same rows.');
    }

    public function testWritesShareXpsrsTransactionAndAfterCommitCallbacks(): void
    {
        $db = $this->project->db();
        $transactions = $this->project->application->get(Transactions::class);
        /** @var ArrayObject<int, string> $committed */
        $committed = new ArrayObject();

        $db->beginTransaction();
        $this->project->em()->persist(new Notebook('Inside'))->run();
        self::assertSame(1, $this->project->application->get(DatabaseManager::class)->database()->getDriver()->getTransactionLevel(), 'Cycle sees DBAL\'s transaction.');
        $transactions->afterCommit(static function () use ($committed): void {
            $committed[] = 'outer';
        });
        self::assertCount(0, $committed, 'Not before the outer transaction commits.');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM notebooks'), 'DBAL sees the write inside the same transaction.');
        $db->commit();
        self::assertSame(['outer'], iterator_to_array($committed));

        $db->beginTransaction();
        $this->project->em()->persist(new Notebook('Rolled back'))->run();
        $transactions->afterCommit(static function () use ($committed): void {
            $committed[] = 'never';
        });
        $db->rollBack();
        self::assertCount(1, $committed, 'The callback registered in the rolled-back transaction never ran.');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM notebooks'), 'Rolling back XPSR\'s transaction undoes the ORM write.');

        // Cycle's own transaction is DBAL's too.
        $this->project->em()->persist(new Notebook('Own'))->run();
        self::assertSame(0, $db->getTransactionNestingLevel());
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM notebooks'));
    }

    public function testQueriesAreReportedLikeDbals(): void
    {
        $this->project->em()->persist(new Notebook('Reported'))->run();
        $sql = array_map(static fn(QueryExecuted $event): string => $event->sql, $this->queries);
        $inserts = array_values(array_filter($sql, static fn(string $statement): bool => stripos($statement, 'INSERT INTO') === 0));
        self::assertCount(1, $inserts, implode("\n", $sql));
        self::assertMatchesRegularExpression('/^INSERT INTO \W?notebooks\W? \(\W?title\W?\) VALUES \(\?\)( RETURNING \W?id\W?)?$/i', $inserts[0], 'Identifiers are quoted as the database wants.');
        foreach ($this->queries as $event) {
            self::assertSame('main', $event->connection);
            self::assertGreaterThanOrEqual(0.0, $event->seconds);
        }
        $insert = $this->queries[array_search($inserts[0], $sql, true)];
        self::assertSame([], $insert->parameters, 'Cycle resolves the parameters for its own log; they are not passed on.');
    }

    public function testTheSchemaCacheIsWrittenUsedAndCleared(): void
    {
        $app = $this->project->application;
        $file = $this->project->root . '/var/cache/cycle-schema.php';
        $cache = new CommandTester($app->get(SchemaCacheCommand::class));
        self::assertSame(0, $cache->execute([]));
        self::assertStringContainsString('Compiled the schema of 2 entities to var/cache/cycle-schema.php.', $cache->getDisplay());
        self::assertFileExists($file);
        $schema = require $file;
        self::assertIsArray($schema);
        self::assertArrayHasKey('note', $schema);

        // A production application (APP_DEBUG off) reads the file instead of the entities.
        file_put_contents($file, "<?php\n\nreturn " . var_export(['ghost' => $schema['note']], true) . ";\n");
        $production = new Project(debug: false, root: $this->project->root);
        self::assertTrue($production->boot()->get(SchemaInterface::class)->defines('ghost'));
        self::assertFalse($production->boot()->get(SchemaInterface::class)->defines('note'));
        $development = new Project(debug: true, root: $this->project->root);
        self::assertFalse($development->boot()->get(SchemaInterface::class)->defines('ghost'), 'With APP_DEBUG on, the entities are compiled on each request.');
        self::assertTrue($development->boot()->get(SchemaInterface::class)->defines('note'));
        $production->db()->close();
        $development->db()->close();

        $show = new CommandTester($app->get(SchemaCommand::class));
        self::assertSame(0, $show->execute([]));
        self::assertStringContainsString('note', $show->getDisplay());
        self::assertStringContainsString(Note::class, $show->getDisplay());
        self::assertStringContainsString('main.notes', $show->getDisplay());
        self::assertStringContainsString('notebook -> notebook', $show->getDisplay());
        self::assertStringContainsString('Schema cache: var/cache/cycle-schema.php (present).', $show->getDisplay());
        self::assertSame(0, $show->execute(['--json' => true]));
        self::assertIsArray(json_decode($show->getDisplay(), true));

        $clear = new CommandTester($app->get(SchemaClearCommand::class));
        self::assertSame(0, $clear->execute([]));
        self::assertStringContainsString('Removed var/cache/cycle-schema.php.', $clear->getDisplay());
        self::assertFileDoesNotExist($file);
        self::assertSame(0, $clear->execute([]));
        self::assertStringContainsString('There is no cached schema at var/cache/cycle-schema.php.', $clear->getDisplay());
        self::assertSame(0, $show->execute([]));
        self::assertStringContainsString('(absent; run php xpsr cycle:schema:cache for production)', $show->getDisplay());
    }

    public function testMakeEntityWritesAClassForItsTable(): void
    {
        $tester = new CommandTester($this->project->application->get(MakeEntityCommand::class));
        self::assertSame(0, $tester->execute(['name' => 'Task']), $tester->getDisplay());
        $file = $this->project->root . '/app/Entity/Task.php';
        self::assertFileExists($file);
        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('namespace App\Entity;', $contents);
        self::assertStringContainsString("#[Entity(table: 'tasks')]", $contents);
        self::assertStringContainsString('class Task', $contents);
        self::assertStringContainsString("#[Column(type: 'primary')]", $contents);
        self::assertStringContainsString('php xpsr make:migration create_tasks_table --diff', $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'an unknown option' => [['metadata_cache' => 'auto'], 'Unknown option cycle.metadata_cache. Known options: connection, entities, schema_cache.'];
        yield 'an empty connection name' => [['connection' => ''], 'cycle.connection must name a connection from database.connections, or be null for the default.'];
        yield 'entities that are not a list' => [['entities' => 'app/Entity'], 'cycle.entities must be a list of directories, relative to the project root.'];
        yield 'a missing directory' => [['entities' => ['src/Nowhere']], 'cycle.entities: the directory src/Nowhere does not exist.'];
        yield 'a schema cache that is not a path' => [['schema_cache' => false], 'cycle.schema_cache must be "auto", the path of a file, or null.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationIsNamed(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        CycleOptions::from($config, $this->project->root);
    }

    public function testTheDefaultsAndTheOptions(): void
    {
        $root = $this->project->root;
        $options = CycleOptions::from([], $root);
        self::assertSame([], $options->entityPaths, 'app/Entity is used only once it exists.');
        self::assertSame($root . '/var/cache/cycle-schema.php', $options->cacheFile);
        self::assertTrue($options->cacheAuto);
        self::assertNull($options->connection);

        mkdir($root . '/app/Entity');
        self::assertSame([$root . '/app/Entity'], CycleOptions::from([], $root)->entityPaths);

        $options = CycleOptions::from(['connection' => 'reporting', 'entities' => ['app'], 'schema_cache' => 'var/schema.php'], $root);
        self::assertSame(['reporting', [$root . '/app'], $root . '/var/schema.php', false], [$options->connection, $options->entityPaths, $options->cacheFile, $options->cacheAuto]);
        self::assertNull(CycleOptions::from(['schema_cache' => null], $root)->cacheFile);
    }

    public function testTheHeapIsSharedWithinAnApplicationOnly(): void
    {
        $this->project->em()->persist(new Notebook('Mine'))->run();
        $other = new Project(root: $this->project->root);
        $other->boot();
        self::assertNotSame($this->project->orm(), $other->orm(), 'A distinct ORM.');
        self::assertSame(1, (int) $other->db()->fetchOne('SELECT COUNT(*) FROM notebooks'));
        $other->db()->close();
    }
}
