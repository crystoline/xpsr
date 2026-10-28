<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Console\MakeMigrationCommand;
use Crystoline\Xpsr\Database\Console\MigrateCommand;
use Crystoline\Xpsr\Database\Console\MigrateRollbackCommand;
use Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry;
use Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\Project;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * make:migration --diff with Doctrine entities, end to end: generate, migrate, validate, roll
 * back. Runs against SQLite, and against MySQL and PostgreSQL when XPSR_TEST_MYSQL_URL or
 * XPSR_TEST_PGSQL_URL name a database the tests may create tables in.
 */
final class DiffMigrationTest extends TestCase
{
    private ?Project $project = null;

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function databases(): iterable
    {
        yield 'sqlite' => [['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']];
        foreach (['mysql' => 'XPSR_TEST_MYSQL_URL', 'pgsql' => 'XPSR_TEST_PGSQL_URL'] as $name => $variable) {
            if (is_string(getenv($variable)) && getenv($variable) !== '') {
                yield $name => [['url' => getenv($variable)]];
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->project === null) {
            return;
        }
        $db = $this->db();
        $schema = $db->createSchemaManager();
        $migrations = $this->project->application->getConfig()->get('database.migrations.table');
        foreach (['notes', $migrations] as $table) {
            if (is_string($table) && $schema->tableExists($table)) {
                $schema->dropTable($table);
            }
        }
        $db->close();
        $this->project->remove();
    }

    /** @param array<string, mixed> $connection */
    #[DataProvider('databases')]
    public function testDiffMigrationsBringTheDatabaseInLineWithTheEntities(array $connection): void
    {
        $this->project = new Project(connection: $connection);
        $this->project->boot();
        $this->dropNotes();

        $make = $this->command(MakeMigrationCommand::class, ['name' => 'create_notes', '--diff' => true]);
        self::assertSame(0, $make->getStatusCode(), $make->getErrorOutput());
        self::assertStringContainsString('Next: review the SQL, then run: php xpsr migrate', $make->getDisplay());
        $files = glob($this->project->root . '/migrations/*_create_notes.php') ?: [];
        self::assertCount(1, $files);
        $contents = (string) file_get_contents($files[0]);
        self::assertMatchesRegularExpression('/CREATE TABLE notes/i', $contents);
        self::assertStringContainsString('created_at', $contents);
        self::assertMatchesRegularExpression('/DROP TABLE notes/i', $contents);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($files[0]) . ' 2>&1', $lint, $code);
        self::assertSame(0, $code, implode("\n", $lint));

        $pending = $this->command(MakeMigrationCommand::class, ['name' => 'again', '--diff' => true]);
        self::assertSame(1, $pending->getStatusCode());
        self::assertStringContainsString('1 migration(s) have not run yet', (string) preg_replace('/\s+/', ' ', $pending->getErrorOutput()));

        self::assertSame(0, $this->command(MigrateCommand::class)->getStatusCode());
        $validate = $this->command(DoctrineOrmServiceRegistry::COMMANDS['orm:validate-schema'][0]);
        self::assertSame(0, $validate->getStatusCode(), $validate->getDisplay() . $validate->getErrorOutput());

        $none = $this->command(MakeMigrationCommand::class, ['name' => 'nothing', '--diff' => true]);
        self::assertSame(0, $none->getStatusCode(), $none->getErrorOutput());
        self::assertSame("The database already matches the schema; no migration written.\n", $none->getDisplay());
        self::assertCount(1, glob($this->project->root . '/migrations/*.php') ?: []);

        self::assertSame(0, $this->command(MigrateRollbackCommand::class)->getStatusCode());
        self::assertFalse($this->db()->createSchemaManager()->tableExists('notes'));
    }

    public function testDiffLeavesTablesTheEntitiesDoNotDescribe(): void
    {
        $this->project = new Project();
        $this->project->boot();
        $this->db()->executeStatement('CREATE TABLE legacy_records (id INTEGER PRIMARY KEY)');
        self::assertSame(0, $this->command(MakeMigrationCommand::class, ['name' => 'create_notes', '--diff' => true])->getStatusCode());
        $contents = (string) file_get_contents((glob($this->project->root . '/migrations/*.php') ?: [''])[0]);
        self::assertStringNotContainsString('legacy_records', $contents);
        self::assertStringNotContainsString('migrations_', $contents, 'The migrations table is never touched.');
    }

    public function testDiffCannotBeCombinedWithAStub(): void
    {
        $this->project = new Project();
        $this->project->boot();
        $tester = $this->command(MakeMigrationCommand::class, ['name' => 'x', '--diff' => true, '--create' => 'notes']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Use --diff on its own', $tester->getErrorOutput());
    }

    /**
     * @param class-string<Command> $command
     * @param array<string, mixed> $input
     */
    private function command(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->project?->application->get($command) ?? throw new \LogicException('No project.'));
        $tester->execute($input, ['interactive' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }

    private function db(): Connection
    {
        return $this->project?->application->get(ConnectionManager::class)->connection() ?? throw new \LogicException('No project.');
    }

    /** A shared test server may still have the table from an interrupted run. */
    private function dropNotes(): void
    {
        $schema = $this->db()->createSchemaManager();
        if ($schema->tableExists('notes')) {
            $schema->dropTable('notes');
        }
    }
}
