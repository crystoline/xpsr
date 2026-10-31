<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Event\QueryExecuted;
use Crystoline\Xpsr\Database\QueryEvents;
use Crystoline\Xpsr\Database\Tests\Fixtures\MemoryLogger;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** QueryExecuted events, log_queries, and slow_queries for the queries DBAL runs. */
final class QueryEventsTest extends TestCase
{
    private Workspace $workspace;
    private MemoryLogger $logger;

    /** @var list<QueryExecuted> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        $this->logger = new MemoryLogger();
        mkdir($this->workspace->root . '/config');
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testEachQueryIsDispatchedWithItsParametersAndTime(): void
    {
        $db = $this->application()->get(ConnectionManager::class)->connection();
        $db->executeStatement('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $db->insert('notes', ['body' => 'first']);
        self::assertSame('first', $db->fetchOne('SELECT body FROM notes WHERE id = ?', [1]));
        $db->executeQuery('SELECT body FROM notes WHERE body = :body', ['body' => 'first'])->fetchOne();

        self::assertSame(
            ['CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)', 'INSERT INTO notes (body) VALUES (?)', 'SELECT body FROM notes WHERE id = ?', 'SELECT body FROM notes WHERE body = ?'],
            array_map(static fn(QueryExecuted $event): string => $event->sql, $this->events),
            'As the driver got them: DBAL turns named parameters into positional ones first.',
        );
        self::assertSame([[], [1 => 'first'], [1 => 1], [1 => 'first']], array_map(static fn(QueryExecuted $event): array => $event->parameters, $this->events));
        foreach ($this->events as $event) {
            self::assertSame('main', $event->connection);
            self::assertGreaterThan(0, $event->seconds);
            self::assertSame($event->seconds * 1000, $event->milliseconds());
        }
        self::assertSame([], $this->logger->records, 'Neither log_queries nor slow_queries is set.');
    }

    public function testQueriesAreLoggedWhenAskedAndSlowOnesAsWarnings(): void
    {
        $db = $this->application(['log_queries' => true, 'slow_queries' => '1 microsecond'])->get(ConnectionManager::class)->connection();
        $db->fetchOne('SELECT 1 + ?', [1]);
        [$debug, $warning] = $this->logger->records;
        self::assertSame(['debug', 'Query on {connection} took {ms} ms: {sql}'], [$debug['level'], $debug['message']]);
        self::assertSame('main', $debug['context']['connection']);
        self::assertSame('SELECT 1 + ?', $debug['context']['sql']);
        self::assertSame([1 => 1], $debug['context']['parameters']);
        self::assertIsFloat($debug['context']['ms']);
        self::assertSame(['warning', 'Slow query on {connection} took {ms} ms: {sql}'], [$warning['level'], $warning['message']], 'Every query takes longer than a microsecond.');
        self::assertCount(1, $this->events, 'Dispatched once, however it is logged.');

        $db = $this->application(['slow_queries' => '1 hour'])->get(ConnectionManager::class)->connection();
        $this->logger->records = [];
        $db->fetchOne('SELECT 1');
        self::assertSame([], $this->logger->records);
    }

    public function testWithoutListenersOrLoggingQueriesAreNotTimed(): void
    {
        $application = $this->application([], events: false);
        self::assertFalse($application->get(QueryEvents::class)->active());
        self::assertSame(2, (int) $application->get(ConnectionManager::class)->connection()->fetchOne('SELECT 1 + 1'));
        self::assertSame([], $this->logger->records);
    }

    public function testAnInvalidSlowQueriesSettingIsExplained(): void
    {
        $application = $this->application(['slow_queries' => 'soon']);
        try {
            $application->get(ConnectionManager::class);
            self::fail('The setting was accepted.');
        } catch (ContainerException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            self::assertSame('database.slow_queries must be a number of seconds above 0, or a period such as "500 milliseconds"; null for none.', $exception->getPrevious()->getMessage());
        }
    }

    #[DataProvider('durations')]
    public function testDurationsAreReadAsSeconds(mixed $value, ?float $seconds): void
    {
        self::assertSame($seconds, QueryEvents::seconds($value, 'database.slow_queries'));
    }

    /** @return iterable<string, array{mixed, float|null}> */
    public static function durations(): iterable
    {
        yield 'null' => [null, null];
        yield 'unset variable' => ['', null];
        yield 'false' => [false, null];
        yield 'seconds' => [2, 2.0];
        yield 'fraction' => [0.25, 0.25];
        yield 'numeric string' => ['1.5', 1.5];
        yield 'milliseconds' => ['500 milliseconds', 0.5];
        yield 'seconds period' => ['2 seconds', 2.0];
        yield 'minutes' => ['1 minute', 60.0];
    }

    #[DataProvider('invalidDurations')]
    public function testInvalidDurationsAreRejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('database.slow_queries must be a number of seconds above 0');
        QueryEvents::seconds($value, 'database.slow_queries');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'words' => ['soon'];
        yield 'array' => [[1]];
    }

    /** @param array<string, mixed> $database */
    private function application(array $database = [], bool $events = true): Application
    {
        $application = new Application($this->workspace->root);
        $config = $application->getConfig();
        $config->set('app.registry', [...($events ? [EventServiceRegistry::class] : []), DatabaseServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'memory' => true]],
            ...$database,
        ]);
        if ($events) {
            $config->set('events.listeners', [QueryExecuted::class => [function (QueryExecuted $event): void {
                $this->events[] = $event;
            }]]);
        }
        $application->getContainer()->instance(LoggerInterface::class, $this->logger);
        $application->boot();

        return $application;
    }
}
