<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Seeder\SeederServiceRegistry;
use Crystoline\Xpsr\Database\Seeder\Testing\SeedsDatabase;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Project;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\RolesSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use Crystoline\Xpsr\Database\Testing\DatabaseAssertions;
use Crystoline\Xpsr\Database\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * SeedsDatabase beside RefreshDatabase, on one SQLite file shared by the tests of this class:
 * setUp() seeds inside the test's transaction, and the rows are gone when the test ends.
 */
final class SeedsDatabaseTest extends TestCase
{
    use RefreshDatabase;
    use DatabaseAssertions;
    use SeedsDatabase;

    private const SUFFIX = 'testing';

    private static string $root;
    private ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/xpsr-seeds-' . bin2hex(random_bytes(6));
        mkdir(self::$root . '/migrations', 0777, true);
        mkdir(self::$root . '/config');
        file_put_contents(self::$root . '/migrations/20260101000000_create_roles.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Crystoline\Xpsr\Database\Migration\Migration;
            use Doctrine\DBAL\Connection;

            return new class extends Migration {
                public function up(Connection $db): void
                {
                    $db->executeStatement('CREATE TABLE roles_testing (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(50) NOT NULL, name VARCHAR(100) NOT NULL, level INTEGER NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT 1, meta CLOB, fee NUMERIC(10, 2), starts_at DATETIME)');
                    $db->executeStatement('CREATE TABLE settings_testing ("key" VARCHAR(100) PRIMARY KEY, value CLOB, "group" VARCHAR(50))');
                    $db->executeStatement('CREATE TABLE role_user_testing (role_id INTEGER NOT NULL, user_id INTEGER NOT NULL, note VARCHAR(100), PRIMARY KEY (role_id, user_id))');
                }

                public function down(Connection $db): void
                {
                    $db->executeStatement('DROP TABLE role_user_testing');
                    $db->executeStatement('DROP TABLE settings_testing');
                    $db->executeStatement('DROP TABLE roles_testing');
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

    protected function setUp(): void
    {
        // RefreshDatabase has run: the tables are there, and the test's transaction is open.
        Tables::$suffix = self::SUFFIX;
        RolesSeeder::$rows = null;
        $this->seed();
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() before #[After] hooks, and the connection must be open for the rollback.
        $this->rollBackDatabaseTransactions();
        $this->app?->get(ConnectionManager::class)->connection()->close();
    }

    public function testSetUpSeededTheDefaultSet(): void
    {
        $this->assertDatabaseCount('roles_testing', 3);
        $this->assertDatabaseHas('roles_testing', ['slug' => 'admin', 'name' => 'Administrator']);
        $this->assertDatabaseCount('settings_testing', 2);
        $this->assertDatabaseEmpty('role_user_testing');

        // A set by its name runs after the sets it requires, which change nothing here.
        $demo = $this->seed('demo');
        self::assertSame(3, $demo->inserted());
        self::assertSame(5, $demo->unchanged());
        $this->assertDatabaseCount('role_user_testing', 3);

        // One seeder by its class, which need not be in a set.
        RolesSeeder::$rows = [['slug' => 'auditor', 'name' => 'Auditor']];
        $one = $this->seed(RolesSeeder::class);
        self::assertNull($one->seeders()[0]->set);
        self::assertSame(1, $one->inserted());
        $this->assertDatabaseHas('roles_testing', ['slug' => 'auditor']);
    }

    #[Depends('testSetUpSeededTheDefaultSet')]
    public function testTheNextTestStartsFromTheSeedDataAlone(): void
    {
        $this->assertDatabaseCount('roles_testing', 3);
        $this->assertDatabaseMissing('roles_testing', ['slug' => 'auditor']);
        $this->assertDatabaseEmpty('role_user_testing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no seed set "staging". The sets in config/seeds.php: reference, demo.');
        $this->seed('staging');
    }

    protected function application(): Application
    {
        if ($this->app === null) {
            $this->app = new Application(self::$root);
            $config = $this->app->getConfig();
            $config->set('app.env', 'testing');
            $config->set('app.registry', [DatabaseServiceRegistry::class, SeederServiceRegistry::class]);
            $config->set('database', [
                'default' => 'main',
                'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
                'migrations' => ['path' => 'migrations', 'table' => 'migrations'],
            ]);
            $config->set('seeds', Project::SEEDS);
            $this->app->boot();
        }

        return $this->app;
    }
}
