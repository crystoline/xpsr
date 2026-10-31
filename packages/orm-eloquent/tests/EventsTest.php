<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests;

use ArrayObject;
use Crystoline\Xpsr\Database\Event\QueryExecuted;
use Crystoline\Xpsr\Database\Transactions;
use Crystoline\Xpsr\Orm\Eloquent\ConnectionResolver;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\ContractRecord;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Project;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted as EloquentQueryExecuted;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Eloquent's queries reach XPSR's QueryExecuted listeners, and its transactions XPSR's afterCommit() callbacks. */
final class EventsTest extends TestCase
{
    private Project $project;

    /** @var list<QueryExecuted> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->project = new Project();
        $config = $this->project->application->getConfig();
        $config->set('app.registry', [EventServiceRegistry::class, ...(array) $config->get('app.registry')]);
        $config->set('events.listeners', [QueryExecuted::class => [function (QueryExecuted $event): void {
            $this->events[] = $event;
        }]]);
        $this->project->boot();
        $schema = $this->project->db()->createSchemaManager();
        if ($schema->tableExists('orm_contract_records')) {
            $schema->dropTable('orm_contract_records');
        }
        $this->project->db()->executeStatement('CREATE TABLE orm_contract_records (id INTEGER NOT NULL, label VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
        $this->events = [];
    }

    protected function tearDown(): void
    {
        $this->project->db()->createSchemaManager()->dropTable('orm_contract_records');
        $this->project->remove();
    }

    public function testEloquentsQueriesAreReportedLikeDbalsAndItsOwnListenersWork(): void
    {
        $listened = [];
        $this->project->application->get(ConnectionResolver::class)->connection()->listen(static function (EloquentQueryExecuted $event) use (&$listened): void {
            $listened[] = $event->sql;
        });
        self::assertSame(["SELECT 'main'"], array_map(static fn(QueryExecuted $event): string => $event->sql, $this->events), 'Making the Eloquent connection asks DBAL for the database name.');
        ContractRecord::query()->create(['id' => 1, 'label' => 'one']);
        self::assertSame('one', ContractRecord::query()->find(1)?->label);
        self::assertSame(1, (int) $this->project->db()->fetchOne('SELECT COUNT(*) FROM orm_contract_records'));

        $sql = array_map(static fn(QueryExecuted $event): string => $event->sql, $this->events);
        self::assertSame(
            ["SELECT 'main'", 'insert into "orm_contract_records" ("id", "label") values (?, ?)', 'select * from "orm_contract_records" where "orm_contract_records"."id" = ? limit 1', 'SELECT COUNT(*) FROM orm_contract_records'],
            $sql,
            'Eloquent\'s queries, and DBAL\'s on the same connection.',
        );
        self::assertSame([1, 'one'], $this->events[1]->parameters);
        foreach ($this->events as $event) {
            self::assertSame('main', $event->connection);
            self::assertGreaterThanOrEqual(0, $event->seconds);
        }
        self::assertCount(2, $listened, 'Eloquent\'s own listen() works, now that its connections have a dispatcher.');
    }

    public function testAfterCommitCallbacksFollowEloquentsTransactions(): void
    {
        $transactions = $this->project->application->get(Transactions::class);
        $db = $this->project->application->get(ConnectionInterface::class);
        /** @var ArrayObject<int, string> $ran */
        $ran = new ArrayObject();
        $db->transaction(static function () use ($transactions, $ran): void {
            ContractRecord::query()->create(['id' => 1, 'label' => 'one']);
            $transactions->afterCommit(static function () use ($ran): void {
                $ran[] = 'committed';
            });
            self::assertSame([], $ran->getArrayCopy());
        });
        self::assertSame(['committed'], $ran->getArrayCopy());

        $thrown = false;
        try {
            $db->transaction(static function () use ($transactions, $ran): void {
                $transactions->afterCommit(static function () use ($ran): void {
                    $ran[] = 'rolled back';
                });
                throw new RuntimeException('Undo.');
            });
        } catch (RuntimeException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'The exception reaches the caller.');
        self::assertSame(['committed'], $ran->getArrayCopy(), 'The rolled-back transaction\'s callback is dropped.');
        self::assertSame(0, $db->transactionLevel());
    }
}
