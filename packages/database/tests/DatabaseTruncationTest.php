<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Testing\DatabaseAssertions;
use Crystoline\Xpsr\Database\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** DatabaseTruncation on one SQLite file shared by the tests of this class: committed data, emptied after each test. */
final class DatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;
    use DatabaseAssertions;

    private static string $root;
    private ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/xpsr-truncate-' . bin2hex(random_bytes(6));
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
                    $db->insert('settings', ['name' => 'currency', 'value' => 'NGN']);
                }

                public function down(Connection $db): void
                {
                    $db->executeStatement('DROP TABLE settings');
                    $db->executeStatement('DROP TABLE notes');
                }
            };
            PHP);
    }

    public static function tearDownAfterClass(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$root);
    }

    protected function tablesToKeep(): array
    {
        return ['settings'];
    }

    public function testChangesAreCommittedAndSeenByAnotherConnection(): void
    {
        $this->assertDatabaseEmpty('notes');
        $manager = $this->application()->get(ConnectionManager::class);
        $manager->connection()->insert('notes', ['body' => 'committed']);
        self::assertSame(1, (int) $manager->open()->fetchOne('SELECT COUNT(*) FROM notes'), 'Another connection sees the row: there is no test transaction.');
        $this->assertDatabaseHas('settings', ['name' => 'currency']);
    }

    #[Depends('testChangesAreCommittedAndSeenByAnotherConnection')]
    public function testTablesAreEmptiedAfterEachTestExceptTheOnesKept(): void
    {
        $this->assertDatabaseEmpty('notes');
        $this->assertDatabaseHas('settings', ['name' => 'currency', 'value' => 'NGN']);
        $this->assertDatabaseCount('migrations', 1);
        $db = $this->application()->get(ConnectionManager::class)->connection();
        $db->insert('notes', ['body' => 'again']);
        self::assertSame(1, (int) $db->fetchOne('SELECT MAX(id) FROM notes'), 'Auto-increment counters start over.');
    }

    protected function application(): Application
    {
        if ($this->app === null) {
            $this->app = new Application(self::$root);
            $this->app->getConfig()->set('app.registry', [DatabaseServiceRegistry::class]);
            $this->app->getConfig()->set('database', [
                'default' => 'main',
                'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
                'migrations' => ['path' => 'migrations', 'table' => 'migrations'],
            ]);
            $this->app->boot();
        }

        return $this->app;
    }
}
