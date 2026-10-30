<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Console\MigrateBaselineCommand;
use Crystoline\Xpsr\Database\Console\MigrateCommand;
use Crystoline\Xpsr\Database\Console\MigrateRollbackCommand;
use Crystoline\Xpsr\Database\Console\MigrateStatusCommand;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * migrate:baseline on a database made as Laravel makes one: the server in XPSR_TEST_PGSQL_URL or
 * XPSR_TEST_MYSQL_URL when set, otherwise SQLite. The baseline is then run on new SQLite
 * databases, so it is shown to work on another kind of database too.
 */
final class MigrateBaselineTest extends TestCase
{
    private Workspace $workspace;
    private Application $application;
    private string $suffix;
    /** @var list<string> tables to drop afterwards, from the main connection */
    private array $created = [];

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        mkdir($this->workspace->root . '/var');
        $this->suffix = bin2hex(random_bytes(3));
        $this->boot();
    }

    protected function tearDown(): void
    {
        $main = $this->db();
        $main->executeStatement("DROP VIEW IF EXISTS v_authors_{$this->suffix}");
        foreach (["xpsr_migrations_{$this->suffix}", ...array_reverse($this->created)] as $table) {
            $main->executeStatement("DROP TABLE IF EXISTS {$table}");
        }
        foreach (['main', 'fresh', 'staging', 'empty'] as $name) {
            $this->db($name)->close();
        }
        $this->workspace->remove();
    }

    public function testTheBaselineRebuildsTheDatabaseElsewhereAndIsRecordedAsRun(): void
    {
        $this->laravelTables($this->db());
        $this->db()->executeStatement("CREATE VIEW v_authors_{$this->suffix} AS SELECT id FROM users_{$this->suffix}");

        $baseline = $this->command(MigrateBaselineCommand::class, ['--exclude' => ["cache_{$this->suffix}"]]);
        $display = (string) preg_replace('/\s+/', ' ', $baseline->getDisplay());
        self::assertSame(Command::SUCCESS, $baseline->getStatusCode(), $display);
        self::assertMatchesRegularExpression('/Wrote migrations\/00000000000000_baseline\.php with the \d+ tables of this (MySQL|MariaDB|PostgreSQL|SQLite) database\./', $display);
        self::assertStringContainsString("Left out: cache_{$this->suffix} (--exclude), laravel_migrations_{$this->suffix} (Laravel's record of migrations).", $display);
        self::assertStringContainsString("Not in the baseline: the views v_authors_{$this->suffix}.", $display);
        self::assertStringContainsString('It builds the same tables as this database has.', $display);
        self::assertStringContainsString('Recorded it as run on this database, without running it.', $display);

        $file = $this->workspace->migrations . '/00000000000000_baseline.php';
        $lint = new Process([PHP_BINARY, '-l', $file]);
        $lint->run();
        self::assertSame(0, $lint->getExitCode(), $lint->getOutput() . $lint->getErrorOutput());
        $code = (string) file_get_contents($file);
        self::assertStringContainsString("->setUnquotedName('users_{$this->suffix}')", $code);
        self::assertStringContainsString('->setOnDeleteAction(ReferentialAction::CASCADE)', $code);
        self::assertStringNotContainsString("cache_{$this->suffix}", $code);

        self::assertSame("Nothing to migrate.\n", $this->command(MigrateCommand::class)->getDisplay(), 'Recorded, so it does not run here.');
        $rollback = $this->command(MigrateRollbackCommand::class);
        self::assertSame(Command::FAILURE, $rollback->getStatusCode());
        self::assertStringContainsString('The baseline is not rolled back: that would drop every table it created.', (string) preg_replace('/\s+/', ' ', $rollback->getDisplay()));

        // A new database gets the same tables from it.
        $migrate = $this->command(MigrateCommand::class, ['--connection' => 'fresh']);
        self::assertSame(Command::SUCCESS, $migrate->getStatusCode(), $migrate->getDisplay());
        $fresh = $this->db('fresh')->createSchemaManager();
        $users = $fresh->introspectTableByUnquotedName("users_{$this->suffix}");
        self::assertSame(['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'], array_map(
            static fn(Column $column): string => $column->getObjectName()->getIdentifier()->getValue(),
            $users->getColumns(),
        ));
        self::assertTrue($users->getColumn('id')->getAutoincrement());
        self::assertFalse($users->getColumn('email_verified_at')->getNotnull());
        $posts = $fresh->introspectTableByUnquotedName("posts_{$this->suffix}");
        self::assertSame('draft', $posts->getColumn('status')->getDefault());
        self::assertSame(2, $posts->getColumn('price')->getScale());
        self::assertCount(1, $posts->getForeignKeys());
        self::assertSame(ReferentialAction::CASCADE, array_values($posts->getForeignKeys())[0]->getOnDeleteAction());
        $unique = array_filter($users->getIndexes(), static fn(Index $index): bool => $index->isUnique() && !$index->isPrimary());
        self::assertCount(1, $unique, 'The unique email index.');
        $this->db('fresh')->insert("users_{$this->suffix}", ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        self::assertSame(1, (int) $this->db('fresh')->fetchOne("SELECT id FROM users_{$this->suffix}"), 'IDs are generated.');
    }

    public function testADatabaseThatHasTheTablesRecordsAnExistingBaseline(): void
    {
        $this->laravelTables($this->db());
        self::assertSame(Command::SUCCESS, $this->command(MigrateBaselineCommand::class, ['--exclude' => ["cache_{$this->suffix}"]])->getStatusCode());

        // Staging already has the tables, as production did.
        $this->laravelTables($this->db('staging'), track: false);
        $staging = $this->command(MigrateBaselineCommand::class, ['--connection' => 'staging']);
        $display = (string) preg_replace('/\s+/', ' ', $staging->getDisplay());
        self::assertSame(Command::SUCCESS, $staging->getStatusCode(), $display);
        self::assertStringContainsString('Recorded migrations/00000000000000_baseline.php as run on this database, without running it.', $display);
        self::assertSame("Nothing to migrate.\n", $this->command(MigrateCommand::class, ['--connection' => 'staging'])->getDisplay());
        self::assertSame(Command::FAILURE, $this->command(MigrateBaselineCommand::class, ['--connection' => 'staging'])->getStatusCode(), 'Only once.');

        // A database without the tables is told to migrate instead.
        $empty = $this->command(MigrateBaselineCommand::class, ['--connection' => 'empty']);
        self::assertSame(Command::FAILURE, $empty->getStatusCode());
        self::assertStringContainsString('This database has none of the tables in migrations/00000000000000_baseline.php. Run php xpsr migrate to create them.', (string) preg_replace('/\s+/', ' ', $empty->getDisplay()));
    }

    public function testABaselineComesBeforeEveryOtherMigration(): void
    {
        $this->created[] = "notes_{$this->suffix}";
        $this->workspace->migration('20260101000000_create_notes', "\$db->executeStatement('CREATE TABLE notes_{$this->suffix} (id INTEGER PRIMARY KEY)');");
        self::assertSame(Command::SUCCESS, $this->command(MigrateCommand::class)->getStatusCode());

        $baseline = $this->command(MigrateBaselineCommand::class);
        self::assertSame(Command::FAILURE, $baseline->getStatusCode());
        self::assertStringContainsString('1 migration(s) have already run on this database. A baseline must come before every other migration', (string) preg_replace('/\s+/', ' ', $baseline->getDisplay()));
        self::assertFileDoesNotExist($this->workspace->migrations . '/00000000000000_baseline.php');
    }

    public function testLaravelsMigrationsTableIsNotTakenForXpsrs(): void
    {
        $this->laravelTables($this->db());
        $this->boot(migrations: "laravel_migrations_{$this->suffix}");

        foreach ([MigrateStatusCommand::class, MigrateCommand::class, MigrateBaselineCommand::class] as $command) {
            $tester = $this->command($command);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $command);
            self::assertStringContainsString(
                "The table laravel_migrations_{$this->suffix} is not XPSR's record of migrations: it has no executed_at column. It looks like Laravel's. Set database.migrations.table to another name, such as xpsr_migrations, so XPSR keeps its own record.",
                (string) preg_replace('/\s+/', ' ', $tester->getDisplay()),
            );
        }
    }

    public function testInProductionTheBaselineNeedsForce(): void
    {
        $this->laravelTables($this->db());
        $this->boot(env: 'production');
        $refused = $this->command(MigrateBaselineCommand::class, [], interactive: false);
        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        self::assertStringContainsString('Pass --force to change the database.', (string) preg_replace('/\s+/', ' ', $refused->getDisplay()));
        self::assertFileDoesNotExist($this->workspace->migrations . '/00000000000000_baseline.php');
        self::assertSame(Command::SUCCESS, $this->command(MigrateBaselineCommand::class, ['--force' => true, '--exclude' => ["cache_{$this->suffix}"]], interactive: false)->getStatusCode());
    }

    public function testAnEmptyDatabaseHasNothingToBaseline(): void
    {
        $tester = $this->command(MigrateBaselineCommand::class, ['--connection' => 'empty']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The database has no tables to make a baseline of.', $tester->getDisplay());
    }

    private function boot(string $env = 'local', ?string $migrations = null): void
    {
        if (isset($this->application)) {
            foreach (['main', 'fresh', 'staging', 'empty'] as $name) {
                $this->db($name)->close();
            }
        }
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $this->application = new Application($this->workspace->root);
        $config = $this->application->getConfig();
        $config->set('app.env', $env);
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class]);
        $sqlite = static fn(string $name): array => ['driver' => 'pdo_sqlite', 'path' => "var/{$name}.sqlite"];
        $config->set('database', [
            'default' => 'main',
            'connections' => [
                'main' => $url !== '' ? ['url' => $url] : $sqlite('main'),
                'fresh' => $sqlite('fresh'),
                'staging' => $sqlite('staging'),
                'empty' => $sqlite('empty'),
            ],
            'migrations' => ['path' => 'migrations', 'table' => $migrations ?? "xpsr_migrations_{$this->suffix}"],
        ]);
        $this->application->boot();
    }

    /** Tables as a Laravel application's migrations make them, with Laravel's migrations table. */
    private function laravelTables(Connection $db, bool $track = true): void
    {
        $s = $this->suffix;
        $id = static fn(): Column => Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setUnsigned(true)->setAutoincrement(true)->create();
        $string = static fn(string $name, int $length = 255, bool $null = false): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->setNotNull(!$null)->create();
        $time = static fn(string $name): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::DATETIME_MUTABLE)->setNotNull(false)->create();
        $primary = static fn(): PrimaryKeyConstraint => PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create();
        $tables = [
            Table::editor()->setUnquotedName("laravel_migrations_{$s}")->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setUnsigned(true)->setAutoincrement(true)->create(),
                $string('migration'),
                Column::editor()->setUnquotedName('batch')->setTypeName(Types::INTEGER)->create(),
            )->setPrimaryKeyConstraint($primary())->create(),
            Table::editor()->setUnquotedName("users_{$s}")->setColumns(
                $id(),
                $string('name'),
                $string('email'),
                $time('email_verified_at'),
                $string('password'),
                $string('remember_token', 100, null: true),
                $time('created_at'),
                $time('updated_at'),
            )->setPrimaryKeyConstraint($primary())
                ->setIndexes(Index::editor()->setUnquotedName("users_{$s}_email_unique")->setType(IndexType::UNIQUE)->setUnquotedColumnNames('email')->create())
                ->create(),
            Table::editor()->setUnquotedName("posts_{$s}")->setColumns(
                $id(),
                Column::editor()->setUnquotedName('user_id')->setTypeName(Types::BIGINT)->setUnsigned(true)->create(),
                $string('title'),
                Column::editor()->setUnquotedName('body')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('published')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create(),
                Column::editor()->setUnquotedName('price')->setTypeName(Types::DECIMAL)->setPrecision(10)->setScale(2)->create(),
                Column::editor()->setUnquotedName('status')->setTypeName(Types::STRING)->setLength(20)->setDefaultValue('draft')->create(),
                $time('created_at'),
            )->setPrimaryKeyConstraint($primary())
                ->setIndexes(Index::editor()->setUnquotedName("posts_{$s}_user_id_status_index")->setUnquotedColumnNames('user_id', 'status')->create())
                ->setForeignKeyConstraints(ForeignKeyConstraint::editor()->setUnquotedName("posts_{$s}_user_id_foreign")->setUnquotedReferencingColumnNames('user_id')->setUnquotedReferencedTableName("users_{$s}")->setUnquotedReferencedColumnNames('id')->setOnDeleteAction(ReferentialAction::CASCADE)->create())
                ->create(),
            Table::editor()->setUnquotedName("cache_{$s}")->setColumns($string('key'), Column::editor()->setUnquotedName('value')->setTypeName(Types::TEXT)->create())->create(),
        ];
        $schema = $db->createSchemaManager();
        foreach ($tables as $table) {
            $schema->createTable($table);
            if ($track) {
                $this->created[] = $table->getObjectName()->getUnqualifiedName()->getValue();
            }
        }
    }

    /**
     * @param class-string<Command> $class
     * @param array<string, mixed> $input
     */
    private function command(string $class, array $input = [], bool $interactive = true): CommandTester
    {
        $command = $this->application->get($class);
        self::assertInstanceOf(Command::class, $command);
        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    }

    private function db(string $name = 'main'): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection($name);
    }
}
