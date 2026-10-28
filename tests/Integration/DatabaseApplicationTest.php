<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Database\DatabaseServiceRegistry;
use Crystoline\Tests\Fixtures\TemporaryDirectory;
use Doctrine\DBAL\Connection;
use Tests\TestCase;

/**
 * The database package in the skeleton application, used the way its README describes:
 * an in-memory SQLite connection for tests, and migrations run through the console.
 */
final class DatabaseApplicationTest extends TestCase
{
    private string $migrations;

    protected function setUp(): void
    {
        $this->migrations = TemporaryDirectory::create('migrations');
        $application = $this->application();
        $config = $application->getConfig();
        $config->set('database.default', 'testing');
        $config->set('database.connections', ['testing' => ['driver' => 'pdo_sqlite', 'memory' => true]]);
        $config->set('database.migrations.path', $this->migrations);
        // The skeleton does not install the package; register its provider as an application would.
        $provider = new DatabaseServiceRegistry($application->getContainer());
        $provider->register();
        $provider->boot();
        file_put_contents($this->migrations . '/2026_01_01_000000_create_tasks_table.php', <<<'PHP'
<?php

use Crystoline\Database\Migration\Migration;
use Doctrine\DBAL\Connection;

return new class extends Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('CREATE TABLE tasks (id INTEGER PRIMARY KEY, title VARCHAR(100) NOT NULL)');
    }

    public function down(Connection $db): void
    {
        $db->executeStatement('DROP TABLE tasks');
    }
};
PHP);
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->migrations);
    }

    public function testMigrationsRunThroughTheConsoleAgainstTheSharedConnection(): void
    {
        [$status, $output] = $this->console('migrate');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Ran 1 migration(s).', $output);

        $db = $this->application()->get(Connection::class);
        $db->insert('tasks', ['title' => 'Write the docs']);
        self::assertSame([['id' => 1, 'title' => 'Write the docs']], $db->fetchAllAssociative('SELECT id, title FROM tasks'));

        [$status, $output] = $this->console('migrate:status', ['--json' => true]);
        self::assertSame(0, $status);
        self::assertSame('ran', json_decode($output, true, flags: JSON_THROW_ON_ERROR)[0]['status']);
    }

    public function testEachTestStartsWithAnEmptyDatabase(): void
    {
        [$status, $output] = $this->console('migrate');
        self::assertSame(0, $status, $output);
        self::assertSame(0, (int) $this->application()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM tasks'));
    }
}
