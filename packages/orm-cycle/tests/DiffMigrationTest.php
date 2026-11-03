<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Console\MakeMigrationCommand;
use Crystoline\Xpsr\Database\Console\MigrateCommand;
use Crystoline\Xpsr\Database\Console\MigrateRollbackCommand;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity\Note;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity\Notebook;
use Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Project;
use Cycle\ORM\Select;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * make:migration --diff with Cycle entities, end to end: generate, migrate, use, roll back.
 * Runs against SQLite, and against MySQL and PostgreSQL when XPSR_TEST_MYSQL_URL or
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
        $this->dropTables();
        $db = $this->db();
        $migrations = $this->project->application->getConfig()->get('database.migrations.table');
        if (is_string($migrations) && $db->createSchemaManager()->tableExists($migrations)) {
            $db->createSchemaManager()->dropTable($migrations);
        }
        $this->project->remove();
    }

    /** @param array<string, mixed> $connection */
    #[DataProvider('databases')]
    public function testDiffMigrationsBringTheDatabaseInLineWithTheEntities(array $connection): void
    {
        $this->project = new Project(connection: $connection);
        $this->project->boot();
        $this->dropTables();

        $make = $this->command(MakeMigrationCommand::class, ['name' => 'create_notes', '--diff' => true]);
        self::assertSame(0, $make->getStatusCode(), $make->getErrorOutput());
        self::assertStringContainsString('Next: review the SQL, then run: php xpsr migrate', $make->getDisplay());
        $files = glob($this->project->root . '/migrations/*_create_notes.php') ?: [];
        self::assertCount(1, $files);
        $contents = (string) file_get_contents($files[0]);
        self::assertMatchesRegularExpression('/CREATE TABLE notebooks/i', $contents);
        self::assertMatchesRegularExpression('/CREATE TABLE notes/i', $contents);
        self::assertStringContainsString('created_at', $contents);
        self::assertStringContainsString('notebook_id', $contents);
        self::assertStringContainsString('notes_pinned_idx', $contents);
        self::assertMatchesRegularExpression('/DROP TABLE notes/i', $contents);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($files[0]) . ' 2>&1', $lint, $code);
        self::assertSame(0, $code, implode("\n", $lint));

        $pending = $this->command(MakeMigrationCommand::class, ['name' => 'again', '--diff' => true]);
        self::assertSame(1, $pending->getStatusCode());
        self::assertStringContainsString('1 migration(s) have not run yet', (string) preg_replace('/\s+/', ' ', $pending->getErrorOutput()));

        self::assertSame(0, $this->command(MigrateCommand::class)->getStatusCode());

        $none = $this->command(MakeMigrationCommand::class, ['name' => 'nothing', '--diff' => true]);
        self::assertSame(0, $none->getStatusCode(), $none->getErrorOutput());
        self::assertSame("The database already matches the schema; no migration written.\n", $none->getDisplay());
        self::assertCount(1, glob($this->project->root . '/migrations/*.php') ?: []);

        // The tables work: a notebook with a note, the foreign key, and the behavior's timestamp.
        $notebook = new Notebook('Ideas');
        $notebook->add(new Note('Write the docs'));
        $this->project->em()->persist($notebook)->run();
        $loaded = (new Select($this->project->orm(), Notebook::class))->load('notes')->fetchOne();
        self::assertInstanceOf(Notebook::class, $loaded);
        self::assertCount(1, $loaded->notes);
        self::assertNotNull($loaded->notes[0]->createdAt);
        self::assertSame($loaded->id, (int) $this->db()->fetchOne('SELECT notebook_id FROM notes'));

        $rollback = $this->command(MigrateRollbackCommand::class);
        self::assertSame(0, $rollback->getStatusCode(), $rollback->getDisplay() . $rollback->getErrorOutput());
        self::assertFalse($this->db()->createSchemaManager()->tableExists('notes'));
        self::assertFalse($this->db()->createSchemaManager()->tableExists('notebooks'));
    }

    public function testDiffLeavesTablesTheEntitiesDoNotDescribe(): void
    {
        $this->project = new Project();
        $this->project->boot();
        $this->db()->executeStatement('CREATE TABLE legacy_records (id INTEGER PRIMARY KEY)');
        $make = $this->command(MakeMigrationCommand::class, ['name' => 'create_notes', '--diff' => true]);
        self::assertSame(0, $make->getStatusCode(), $make->getErrorOutput());
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
        $tester = new CommandTester($this->project?->application->get($command) ?? throw new LogicException('No project.'));
        $tester->execute($input, ['interactive' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }

    private function db(): Connection
    {
        return $this->project?->application->get(ConnectionManager::class)->connection() ?? throw new LogicException('No project.');
    }

    /** A shared test server may still have the tables from an interrupted run. */
    private function dropTables(): void
    {
        $schema = $this->db()->createSchemaManager();
        foreach (['notes', 'notebooks', 'legacy_records'] as $table) {
            if ($schema->tableExists($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
