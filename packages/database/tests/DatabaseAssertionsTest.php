<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Closure;
use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Testing\DatabaseAssertions;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseAssertionsTest extends TestCase
{
    use DatabaseAssertions;

    private Workspace $workspace;
    private Application $app;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        $this->app = new Application($this->workspace->root);
        $this->app->getConfig()->set('app.registry', [DatabaseServiceRegistry::class]);
        $this->app->getConfig()->set('database', [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'other' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ],
        ]);
        $this->app->boot();
        $db = $this->app->get(ConnectionManager::class)->connection();
        $db->executeStatement('CREATE TABLE payments (id INTEGER PRIMARY KEY, reference TEXT NOT NULL, amount INTEGER NOT NULL, paid INTEGER NOT NULL, note TEXT NULL)');
        $db->executeStatement('CREATE TABLE refunds (id INTEGER PRIMARY KEY)');
        $db->insert('payments', ['reference' => 'PAY-1', 'amount' => 500, 'paid' => 1, 'note' => null]);
        $db->insert('payments', ['reference' => 'PAY-2', 'amount' => 250, 'paid' => 0, 'note' => 'partial']);
        $this->app->get(ConnectionManager::class)->connection('other')->executeStatement('CREATE TABLE payments (id INTEGER PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testRowsAreFoundByTheirColumns(): void
    {
        $this->assertDatabaseHas('payments', ['reference' => 'PAY-1']);
        $this->assertDatabaseHas('payments', ['reference' => 'PAY-1', 'amount' => 500, 'paid' => true, 'note' => null]);
        $this->assertDatabaseHas('payments', ['paid' => false, 'note' => 'partial']);
        $this->assertDatabaseMissing('payments', ['reference' => 'PAY-9']);
        $this->assertDatabaseMissing('payments', ['reference' => 'PAY-1', 'amount' => 250]);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseEmpty('refunds');
        $this->assertDatabaseEmpty('payments', 'other');
        $this->assertDatabaseMissing('payments', ['id' => 1], 'other');
    }

    /** @param Closure(self): void $assertion */
    #[DataProvider('failures')]
    public function testAFailureShowsTheRowsNearby(Closure $assertion, string $message): void
    {
        try {
            $assertion($this);
        } catch (AssertionFailedError $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }
        Assert::fail('The assertion passed.');
    }

    /** @return iterable<string, array{Closure(self): void, string}> */
    public static function failures(): iterable
    {
        yield 'has, with rows that match the first column' => [
            static fn(self $test) => $test->assertDatabaseHas('payments', ['reference' => 'PAY-1', 'amount' => 999]),
            "Failed asserting that a row in payments matches {\"reference\":\"PAY-1\",\"amount\":999}. Rows with reference = \"PAY-1\":\n[\n    {\n        \"id\": 1,\n        \"reference\": \"PAY-1\",\n        \"amount\": 500,",
        ];
        yield 'has, with no row for the first column' => [
            static fn(self $test) => $test->assertDatabaseHas('payments', ['reference' => 'PAY-9']),
            'Failed asserting that a row in payments matches {"reference":"PAY-9"}. No row has reference = "PAY-9".',
        ];
        yield 'has, in an empty table' => [
            static fn(self $test) => $test->assertDatabaseHas('refunds', ['id' => 1]),
            'Failed asserting that a row in refunds matches {"id":1}. No row has id = 1.',
        ];
        yield 'missing' => [
            static fn(self $test) => $test->assertDatabaseMissing('payments', ['paid' => true]),
            'Failed asserting that no row in payments matches {"paid":true}: 1 do.',
        ];
        yield 'count' => [
            static fn(self $test) => $test->assertDatabaseCount('payments', 5),
            'Failed asserting that payments has 5 rows: it has 2.',
        ];
        yield 'empty' => [
            static fn(self $test) => $test->assertDatabaseEmpty('payments'),
            'Failed asserting that payments has 0 rows: it has 2.',
        ];
    }

    protected function application(): Application
    {
        return $this->app;
    }
}
