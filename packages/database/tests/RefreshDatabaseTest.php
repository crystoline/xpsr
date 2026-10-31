<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Testing\DatabaseAssertions;
use Crystoline\Xpsr\Database\Testing\RefreshDatabase;
use Crystoline\Xpsr\Database\Testing\TestDatabases;
use Crystoline\Xpsr\Database\Transactions;
use LogicException;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * RefreshDatabase on one SQLite file shared by the tests of this class: migrated from nothing
 * before the first, rolled back after each.
 */
final class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;
    use DatabaseAssertions;

    private static string $root;
    private ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/xpsr-refresh-' . bin2hex(random_bytes(6));
        mkdir(self::$root . '/migrations', 0777, true);
        mkdir(self::$root . '/config');
        file_put_contents(self::$root . '/migrations/20260101000000_create_notes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Crystoline\Xpsr\Database\Migration\Migration;
            use Doctrine\DBAL\Connection;

            return new class extends Migration {
                public function up(Connection $db): void
                {
                    $db->executeStatement('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)');
                    $db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
                    $db->executeStatement('CREATE VIEW note_count AS SELECT COUNT(*) AS n FROM notes');
                }

                public function down(Connection $db): void
                {
                    $db->executeStatement('DROP VIEW note_count');
                    $db->executeStatement('DROP TABLE settings');
                    $db->executeStatement('DROP TABLE notes');
                }
            };
            PHP);
        // What a database from an earlier run might hold: a table the migrations do not know, and old rows.
        $db = self::makeApplication()->get(ConnectionManager::class)->connection();
        $db->executeStatement('CREATE TABLE leftovers (id INTEGER PRIMARY KEY)');
        $db->executeStatement('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $db->insert('notes', ['body' => 'from an earlier run']);
        $db->close();
    }

    public static function tearDownAfterClass(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$root);
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() before #[After] hooks, and the connection must be open for the rollback.
        $this->rollBackDatabaseTransactions();
        $this->app?->get(ConnectionManager::class)->connection()->close();
    }

    public function testTheDatabaseIsMigratedFromNothingBeforeTheFirstTest(): void
    {
        $schema = $this->application()->get(ConnectionManager::class)->connection()->createSchemaManager();
        self::assertFalse($schema->tableExists('leftovers'), 'Every table was dropped first.');
        self::assertTrue($schema->tableExists('migrations'));
        self::assertTrue($schema->tableExists('settings'));
        self::assertCount(1, $schema->listViews());
        $this->assertDatabaseEmpty('notes');
        self::assertFalse($this->application()->get(Transactions::class)->inTransaction(), 'The test\'s transaction does not count for afterCommit().');

        $this->application()->get(ConnectionManager::class)->connection()->insert('notes', ['body' => 'in the test']);
        $this->assertDatabaseHas('notes', ['body' => 'in the test']);
    }

    #[Depends('testTheDatabaseIsMigratedFromNothingBeforeTheFirstTest')]
    public function testTheNextTestStartsEmptyWithoutMigratingAgain(): void
    {
        $this->assertDatabaseEmpty('notes');
        self::assertTrue($this->application()->get(ConnectionManager::class)->connection()->createSchemaManager()->tableExists('settings'));
    }

    #[Depends('testTheNextTestStartsEmptyWithoutMigratingAgain')]
    public function testARefreshIsRefusedInProduction(): void
    {
        $application = self::makeApplication();
        $application->getConfig()->set('app.env', 'production');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('RefreshDatabase drops every table on the "main" connection. It refused because app.env is production.');
        $application->getContainer()->instance(ConnectionManager::class, new ConnectionManager(['default' => 'main', 'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/other.sqlite']]], self::$root));
        TestDatabases::refresh($application);
    }

    protected function application(): Application
    {
        return $this->app ??= self::makeApplication();
    }

    private static function makeApplication(): Application
    {
        $application = new Application(self::$root);
        $application->getConfig()->set('app.registry', [DatabaseServiceRegistry::class]);
        $application->getConfig()->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['path' => 'migrations', 'table' => 'migrations'],
        ]);
        $application->boot();

        return $application;
    }
}
