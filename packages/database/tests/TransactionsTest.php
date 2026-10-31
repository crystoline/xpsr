<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\Connection;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Tests\Fixtures\MemoryLogger;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Database\Transactions;
use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Transactions::afterCommit() on the connections of ConnectionManager, nested and across connections. */
final class TransactionsTest extends TestCase
{
    private Workspace $workspace;
    private Application $app;
    private MemoryLogger $logger;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        $this->app = new Application($this->workspace->root);
        $this->app->getConfig()->set('app.registry', [DatabaseServiceRegistry::class]);
        $this->app->getConfig()->set('database', [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'pdo_sqlite', 'path' => 'var/main.sqlite'],
                'other' => ['driver' => 'pdo_sqlite', 'path' => 'var/other.sqlite'],
            ],
        ]);
        $this->logger = new MemoryLogger();
        $this->app->getContainer()->instance(LoggerInterface::class, $this->logger);
        $this->app->boot();
        $this->connection('main')->executeStatement('CREATE TABLE notes (body TEXT)');
    }

    protected function tearDown(): void
    {
        foreach (['main', 'other'] as $name) {
            $this->connection($name)->close();
        }
        $this->workspace->remove();
    }

    public function testCallbacksWaitForTheOutermostCommitAndRollbacksDropThem(): void
    {
        $db = $this->connection('main');
        $transactions = $this->transactions();
        self::assertInstanceOf(Connection::class, $db, 'XPSR\'s connections report their transactions.');
        self::assertFalse($transactions->inTransaction());

        $transactions->afterCommit($this->record('at once'));
        self::assertSame(['at once'], $this->ran, 'Outside a transaction, a callback runs at once.');

        $db->beginTransaction();
        self::assertTrue($transactions->inTransaction());
        self::assertTrue($transactions->inTransaction('main'));
        self::assertFalse($transactions->inTransaction('other'));
        $db->insert('notes', ['body' => 'kept']);
        $transactions->afterCommit($this->record('outer'));
        $db->beginTransaction();
        $transactions->afterCommit($this->record('dropped with the savepoint'));
        $db->rollBack();
        $db->beginTransaction();
        $transactions->afterCommit($this->record('inner'));
        $db->commit();
        self::assertSame(['at once'], $this->ran, 'Nothing runs until the outermost transaction commits.');
        $db->commit();
        self::assertSame(['at once', 'outer', 'inner'], $this->ran, 'In order; the rolled-back savepoint\'s callback is gone.');
        self::assertFalse($transactions->inTransaction());

        $db->beginTransaction();
        $transactions->afterCommit($this->record('never'));
        $db->rollBack();
        self::assertSame(['at once', 'outer', 'inner'], $this->ran);

        $result = $db->transactional(function (DbalConnection $db) use ($transactions): string {
            $transactions->afterCommit($this->record('transactional'));

            return 'done';
        });
        self::assertSame('done', $result);
        self::assertSame(['at once', 'outer', 'inner', 'transactional'], $this->ran);
    }

    public function testACallbackWaitsForTheTransactionThatBeganLastOrForTheConnectionNamed(): void
    {
        $main = $this->connection('main');
        $other = $this->connection('other');
        $transactions = $this->transactions();

        $main->beginTransaction();
        $other->beginTransaction();
        $transactions->afterCommit($this->record('other, begun last'));
        $transactions->afterCommit($this->record('main, by name'), 'main');
        $other->commit();
        self::assertSame(['other, begun last'], $this->ran);
        $main->commit();
        self::assertSame(['other, begun last', 'main, by name'], $this->ran);

        $main->beginTransaction();
        $transactions->afterCommit($this->record('other has no transaction'), 'other');
        self::assertSame(['other, begun last', 'main, by name', 'other has no transaction'], $this->ran, 'At once: the named connection has no transaction open.');
        $main->rollBack();

        $own = $this->app->get(ConnectionManager::class)->open('main');
        $own->beginTransaction();
        self::assertFalse($transactions->inTransaction(), 'Connections from open() are outside the application\'s transactions.');
        $own->rollBack();
        $own->close();
    }

    public function testACallbackThatThrowsDoesNotStopTheOthersAndReachesTheCodeThatCommitted(): void
    {
        $db = $this->connection('main');
        $transactions = $this->transactions();

        try {
            $db->transactional(function () use ($transactions): void {
                $transactions->afterCommit(static function (): void {
                    throw new RuntimeException('First.');
                });
                $transactions->afterCommit($this->record('still runs'));
                $transactions->afterCommit(static function (): void {
                    throw new RuntimeException('Second.');
                });
            });
            self::fail('The callback\'s exception was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('First.', $exception->getMessage(), 'The callback\'s own exception, not transactional()\'s rollback failure.');
        }
        self::assertSame(['still runs'], $this->ran);
        self::assertSame(['error: An after-commit callback failed: Second.'], $this->logger->lines(), 'Only the exceptions that are not thrown are logged.');
        self::assertFalse($db->isTransactionActive());
        self::assertFalse($transactions->inTransaction());

        $db->transactional(function () use ($transactions): void {
            $transactions->afterCommit($this->record('next transaction'));
        });
        self::assertSame(['still runs', 'next transaction'], $this->ran, 'The connection works as before afterwards.');

        $db->beginTransaction();
        $this->expectException(\Doctrine\DBAL\ConnectionException::class);
        $db->rollBack();
        $db->rollBack();
    }

    private function record(string $what): \Closure
    {
        return function () use ($what): void {
            $this->ran[] = $what;
        };
    }

    private function transactions(): Transactions
    {
        return $this->app->get(Transactions::class);
    }

    private function connection(string $name): DbalConnection
    {
        return $this->app->get(ConnectionManager::class)->connection($name);
    }
}
