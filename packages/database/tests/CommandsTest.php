<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Console\MakeMigrationCommand;
use Crystoline\Xpsr\Database\Console\MigrateCommand;
use Crystoline\Xpsr\Database\Console\MigrateRollbackCommand;
use Crystoline\Xpsr\Database\Console\MigrateStatusCommand;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
{
    private Workspace $workspace;
    private Application $application;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        $this->application = new Application($this->workspace->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class]);
        $config->set('database', [
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'pdo_sqlite', 'path' => 'var/database.sqlite']],
            'migrations' => ['path' => 'migrations', 'table' => 'migrations'],
        ]);
        $this->application->boot();
    }

    protected function tearDown(): void
    {
        $this->application->get(Connection::class)->close();
        $this->workspace->remove();
    }

    public function testTheProviderRegistersServicesAndCommands(): void
    {
        $connection = $this->application->get(Connection::class);
        self::assertSame($connection, $this->application->get(ConnectionManager::class)->connection());
        self::assertSame($this->workspace->migrations, $this->application->get(MigratorFactory::class)->directory());
        $commands = $this->application->get(CommandRegistry::class)->all();
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'make:migration', 'make:session-table'] as $name) {
            self::assertArrayHasKey($name, $commands);
        }
    }

    public function testMigrateStatusAndRollbackReportEachMigration(): void
    {
        $this->workspace->migration('20260101000000_create_users', "\$db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');", "\$db->executeStatement('DROP TABLE users');");
        $this->workspace->migration('20260102000000_create_posts', "\$db->executeStatement('CREATE TABLE posts (id INTEGER PRIMARY KEY)');", "\$db->executeStatement('DROP TABLE posts');");

        $status = $this->command(MigrateStatusCommand::class);
        self::assertSame(0, $status->getStatusCode());
        self::assertStringContainsString('20260101000000_create_users', $status->getDisplay());
        self::assertStringContainsString('0 ran, 2 pending.', $status->getDisplay());

        $migrate = $this->command(MigrateCommand::class);
        self::assertSame(0, $migrate->getStatusCode());
        self::assertSame(
            "Migrating: 20260101000000_create_users\nMigrated:  20260101000000_create_users\n"
            . "Migrating: 20260102000000_create_posts\nMigrated:  20260102000000_create_posts\nRan 2 migration(s).\n",
            $migrate->getDisplay(),
        );
        self::assertSame("Nothing to migrate.\n", $this->command(MigrateCommand::class)->getDisplay());

        $json = $this->command(MigrateStatusCommand::class, ['--json' => true]);
        self::assertSame([['ran', 1], ['ran', 1]], array_map(
            static fn(array $row): array => [$row['status'], $row['batch']],
            json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        ));

        $rollback = $this->command(MigrateRollbackCommand::class, ['--steps' => '1']);
        self::assertSame(0, $rollback->getStatusCode());
        self::assertSame("Rolling back: 20260102000000_create_posts\nRolled back:  20260102000000_create_posts\nRolled back 1 migration(s).\n", $rollback->getDisplay());
        self::assertSame(0, $this->command(MigrateRollbackCommand::class)->getStatusCode());
        self::assertSame("Nothing to roll back.\n", $this->command(MigrateRollbackCommand::class)->getDisplay());
    }

    public function testFailuresAreReportedOnStderrWithStatusOne(): void
    {
        $this->workspace->migration('20260101000000_create_users', "\$db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');");
        $this->workspace->migration('20260102000000_broken', "\$db->executeStatement('NOT SQL');");
        $migrate = $this->command(MigrateCommand::class);
        self::assertSame(1, $migrate->getStatusCode());
        self::assertStringContainsString('Migration 20260102000000_broken failed', $migrate->getErrorOutput());
        self::assertStringContainsString('The 1 migration(s) before it in this run completed.', $migrate->getErrorOutput());
        self::assertStringNotContainsString('Ran ', $migrate->getDisplay());

        $this->application->getConfig()->set('database.default', 'missing');
        $status = new CommandTester(new MigrateStatusCommand(
            new MigratorFactory(new ConnectionManager($this->application->getConfig()->get('database'), $this->workspace->root), [], $this->workspace->root),
            $this->application->getConfig(),
        ));
        self::assertSame(1, $status->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Unknown database connection "missing"', $status->getErrorOutput());
    }

    public function testProductionNeedsForceOrConfirmation(): void
    {
        $this->workspace->migration('20260101000000_create_users', "\$db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');", "\$db->executeStatement('DROP TABLE users');");
        $this->application->getConfig()->set('app.env', 'production');

        $refused = $this->command(MigrateCommand::class);
        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('Pass --force to change the database.', (string) preg_replace('/\s+/', ' ', $refused->getErrorOutput()));

        $declined = new CommandTester($this->application->get(MigrateCommand::class));
        $declined->setInputs(['no']);
        self::assertSame(1, $declined->execute([], ['interactive' => true]));
        self::assertStringContainsString('The application is in production. Run the migrations?', $declined->getDisplay());

        self::assertSame(0, $this->command(MigrateCommand::class, ['--force' => true])->getStatusCode());
        self::assertSame(1, $this->command(MigrateRollbackCommand::class)->getStatusCode());
        $confirmed = new CommandTester($this->application->get(MigrateRollbackCommand::class));
        $confirmed->setInputs(['yes']);
        self::assertSame(0, $confirmed->execute([], ['interactive' => true]));
    }

    public function testRollbackRejectsAnInvalidStepCount(): void
    {
        foreach (['0', '-1', 'two', '1.5'] as $steps) {
            $tester = $this->command(MigrateRollbackCommand::class, ['--steps' => $steps]);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), $steps);
            self::assertStringContainsString('--steps must be a whole number of at least 1.', $tester->getErrorOutput());
        }
    }

    public function testMakeMigrationWritesTimestampedFilesThatRun(): void
    {
        $make = fn(array $input): CommandTester => $this->runCommand(new MakeMigrationCommand(
            $this->application->get(MigratorFactory::class),
            $this->application->get(ConnectionManager::class),
            clock: fn(): int => 1767225600 + count(glob($this->workspace->migrations . '/*.php') ?: []),
        ), $input);

        $create = $make(['name' => 'CreateUsersTable', '--create' => 'users']);
        self::assertSame(0, $create->getStatusCode(), $create->getErrorOutput());
        self::assertSame("Created migrations/20260101000000_create_users_table.php\nNext: fill in up() and down(), then run: php xpsr migrate\n", $create->getDisplay());
        self::assertSame(0, $make(['name' => 'add_email_to_users', '--table' => 'users'])->getStatusCode());
        self::assertSame(0, $make(['name' => 'seed-roles'])->getStatusCode());
        self::assertSame([
            '20260101000000_create_users_table.php',
            '20260101000001_add_email_to_users.php',
            '20260101000002_seed_roles.php',
        ], array_map(basename(...), glob($this->workspace->migrations . '/*.php') ?: []));
        foreach (glob($this->workspace->migrations . '/*.php') ?: [] as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $code);
            self::assertSame(0, $code, implode("\n", $lint));
        }

        // The generated migrations run as written: create the table, change nothing, roll back.
        self::assertSame(0, $this->command(MigrateCommand::class)->getStatusCode());
        $schema = $this->application->get(Connection::class)->createSchemaManager();
        self::assertTrue($schema->tableExists('users'));
        self::assertCount(1, $schema->introspectTableColumnsByUnquotedName('users'), 'The create stub makes only the id column.');
        self::assertSame(0, $this->command(MigrateRollbackCommand::class)->getStatusCode());
        self::assertFalse($schema->tableExists('users'));
    }

    public function testMakeMigrationRejectsInvalidInputAndDuplicateNames(): void
    {
        self::assertSame(0, $this->command(MakeMigrationCommand::class, ['name' => 'create_users_table'])->getStatusCode());
        $duplicate = $this->command(MakeMigrationCommand::class, ['name' => 'create_users_table']);
        self::assertSame(1, $duplicate->getStatusCode());
        self::assertStringContainsString('A migration named create_users_table already exists', $duplicate->getErrorOutput());

        foreach ([
            [['name' => '9lives'], 'must start with a letter'],
            [['name' => '../escape'], 'must start with a letter'],
            [['name' => 'x', '--create' => 'a', '--table' => 'b'], 'Use --create or --table, not both.'],
            [['name' => 'x', '--create' => 'bad-name'], '--create must be a plain table name'],
            [['name' => 'x', '--table' => "users'); DROP"], '--table must be a plain table name'],
        ] as [$input, $message]) {
            $tester = $this->command(MakeMigrationCommand::class, $input);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), json_encode($input, JSON_THROW_ON_ERROR));
            self::assertStringContainsString($message, $tester->getErrorOutput());
        }
        self::assertCount(1, glob($this->workspace->migrations . '/*.php') ?: []);
    }

    public function testMigrationNamesAreNormalized(): void
    {
        self::assertSame('create_users_table', MakeMigrationCommand::normalizeName('CreateUsersTable'));
        self::assertSame('add_api_key_to_users', MakeMigrationCommand::normalizeName('AddAPIKeyToUsers'));
        self::assertSame('seed_roles', MakeMigrationCommand::normalizeName(' seed-roles '));
    }

    /**
     * @param class-string<Command> $command
     * @param array<string, mixed> $input
     */
    private function command(string $command, array $input = []): CommandTester
    {
        return $this->runCommand($this->application->get($command), $input);
    }

    /** @param array<string, mixed> $input */
    private function runCommand(Command $command, array $input): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }
}
