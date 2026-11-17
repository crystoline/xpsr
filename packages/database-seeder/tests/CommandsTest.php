<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests;

use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\Seeder\Console\MakeSeederCommand;
use Crystoline\Xpsr\Database\Seeder\Console\SeedCommand;
use Crystoline\Xpsr\Database\Seeder\Console\SeedListCommand;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Project;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\FailingSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\MembersSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\RolesSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\SettingsSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** The seed, seed:list, and make:seeder commands. */
final class CommandsTest extends TestCase
{
    private ?Project $project = null;

    protected function setUp(): void
    {
        RolesSeeder::$rows = null;
    }

    protected function tearDown(): void
    {
        $this->project?->remove();
        $this->project = null;
    }

    public function testTheCommandsAreRegistered(): void
    {
        $project = $this->project();
        $names = array_keys($project->application->get(CommandRegistry::class)->all());
        self::assertContains('seed', $names);
        self::assertContains('seed:list', $names);
        self::assertContains('make:seeder', $names);
    }

    public function testSeedLoadsTheDefaultSetAndSaysWhatItDid(): void
    {
        $this->project();
        $roles = Tables::name('roles');
        $settings = Tables::name('settings');

        $first = $this->command(SeedCommand::class);
        self::assertSame(Command::SUCCESS, $first->getStatusCode(), $first->getErrorOutput());
        self::assertSame(
            [
                'Seeding reference',
                '  ' . RolesSeeder::class,
                "    {$roles}: 3 inserted",
                '  ' . SettingsSeeder::class,
                "    {$settings}: 2 inserted",
                'Seeded: 5 inserted, 0 updated, 0 deleted.',
            ],
            self::lines($first),
        );

        $second = $this->command(SeedCommand::class);
        self::assertSame(
            [
                'Seeding reference',
                '  ' . RolesSeeder::class,
                "    {$roles}: 3 unchanged",
                '  ' . SettingsSeeder::class,
                "    {$settings}: 2 unchanged",
                'Nothing to change: the data is as the seeders describe it.',
            ],
            self::lines($second),
        );

        // With -v, the rows that changed are listed by their key.
        RolesSeeder::$rows = [['slug' => 'admin', 'name' => 'Admin'], ['slug' => 'editor', 'name' => 'Editor']];
        $verbose = $this->command(SeedCommand::class, ['--only' => 'RolesSeeder'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        self::assertSame(
            [
                'Seeding reference',
                '  ' . RolesSeeder::class,
                "    {$roles}: 1 inserted, 1 updated",
                '      ~ slug=admin (name)',
                '      + slug=editor',
                'Seeded: 1 inserted, 1 updated, 0 deleted.',
            ],
            self::lines($verbose),
        );
    }

    public function testADryRunListsTheChangesAndMakesNone(): void
    {
        $project = $this->project();
        $dry = $this->command(SeedCommand::class, ['set' => ['demo'], '--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $dry->getStatusCode(), $dry->getErrorOutput());
        $lines = self::lines($dry);
        self::assertSame('Seeding reference', $lines[0]);
        self::assertContains('      + slug=admin', $lines);
        self::assertContains('      + key=invoice.due_days', $lines);
        self::assertContains('Seeding demo', $lines);
        self::assertContains('  ' . MembersSeeder::class, $lines);
        self::assertContains('    ' . Tables::name('role_user') . ': 3 inserted', $lines);
        self::assertSame('Dry run, nothing was changed. A run would give: 8 inserted, 0 updated, 0 deleted.', end($lines));
        self::assertSame([], $project->rows('roles', 'slug'));

        self::assertSame(Command::SUCCESS, $this->command(SeedCommand::class, ['set' => ['demo']])->getStatusCode());
        $settled = self::lines($this->command(SeedCommand::class, ['set' => ['demo', 'reference'], '--dry-run' => true]));
        self::assertSame('Dry run: there is nothing to change.', end($settled));
    }

    public function testSeedReportsWhatWentWrongAndChangesNothing(): void
    {
        $project = $this->project(['sets' => ['reference' => [RolesSeeder::class, FailingSeeder::class]]]);

        $failed = $this->command(SeedCommand::class);
        self::assertSame(Command::FAILURE, $failed->getStatusCode());
        self::assertSame('', $failed->getDisplay());
        self::assertStringContainsString('FailingSeeder failed, and nothing was changed: The price list could not be read.', self::flat($failed->getErrorOutput()));
        self::assertSame([], $project->rows('roles', 'slug'));

        $unknown = $this->command(SeedCommand::class, ['set' => ['staging']]);
        self::assertSame(Command::FAILURE, $unknown->getStatusCode());
        self::assertStringContainsString('There is no seed set "staging". The sets in config/seeds.php: reference.', self::flat($unknown->getErrorOutput()));

        $only = $this->command(SeedCommand::class, ['--only' => 'PlansSeeder']);
        self::assertSame(Command::FAILURE, $only->getStatusCode());
        self::assertStringContainsString('No seeder of the sets is named "PlansSeeder".', self::flat($only->getErrorOutput()));
    }

    public function testProductionNeedsForceOrConfirmation(): void
    {
        $project = $this->project(environment: 'production');

        $refused = $this->command(SeedCommand::class);
        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        self::assertStringContainsString(
            'The application is in production (APP_ENV=production). Pass --force to load the seed data, or --dry-run to see what it would change.',
            self::flat($refused->getErrorOutput()),
        );
        self::assertSame([], $project->rows('roles', 'slug'));

        // A dry run changes nothing, so it does not ask.
        $dry = $this->command(SeedCommand::class, ['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $dry->getStatusCode());
        self::assertSame([], $project->rows('roles', 'slug'));

        $declined = new CommandTester($project->application->get(SeedCommand::class));
        $declined->setInputs(['no']);
        self::assertSame(Command::FAILURE, $declined->execute([], ['interactive' => true]));
        self::assertStringContainsString('The application is in production. Load the seed data?', $declined->getDisplay());
        self::assertSame([], $project->rows('roles', 'slug'));

        // A set that is not for production is refused before anything is asked.
        $demo = $this->command(SeedCommand::class, ['set' => ['demo'], '--force' => true]);
        self::assertSame(Command::FAILURE, $demo->getStatusCode());
        self::assertStringContainsString('The "demo" set is for local, testing (seeds.sets.demo.environments), and APP_ENV is production.', self::flat($demo->getErrorOutput()));
        self::assertSame([], $project->rows('roles', 'slug'));

        $confirmed = new CommandTester($project->application->get(SeedCommand::class));
        $confirmed->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $confirmed->execute(['--only' => 'RolesSeeder'], ['interactive' => true]));
        self::assertCount(3, $project->rows('roles', 'slug'));

        self::assertSame(Command::SUCCESS, $this->command(SeedCommand::class, ['--force' => true])->getStatusCode());
        self::assertCount(2, $project->rows('settings', 'key'));
    }

    public function testSeedListShowsTheSetsAndWhetherTheyRunHere(): void
    {
        $project = $this->project(environment: 'production');
        $json = $this->command(SeedListCommand::class, ['--json' => true]);
        self::assertSame(Command::SUCCESS, $json->getStatusCode());
        self::assertSame(
            [
                ['set' => 'reference', 'default' => true, 'environments' => null, 'runs_here' => true, 'requires' => [], 'seeders' => [RolesSeeder::class, SettingsSeeder::class]],
                ['set' => 'demo', 'default' => false, 'environments' => ['local', 'testing'], 'runs_here' => false, 'requires' => ['reference'], 'seeders' => [MembersSeeder::class]],
            ],
            json_decode($json->getDisplay(), true),
        );

        $table = self::flat($this->command(SeedListCommand::class)->getDisplay());
        self::assertStringContainsString('Runs in production', $table);
        self::assertStringContainsString('reference (default) all yes', $table);
        self::assertStringContainsString('demo local, testing no reference ' . MembersSeeder::class, $table);

        $project->remove();
        $this->project(['sets' => []]);
        self::assertSame("config/seeds.php defines no sets.\n", $this->command(SeedListCommand::class)->getDisplay());
        self::assertSame("[]\n", $this->command(SeedListCommand::class, ['--json' => true])->getDisplay());
    }

    public function testMakeSeederWritesASeederToFillIn(): void
    {
        $project = $this->project();

        $made = $this->command(MakeSeederCommand::class, ['name' => 'PlanTiersSeeder']);
        self::assertSame(Command::SUCCESS, $made->getStatusCode(), $made->getErrorOutput());
        self::assertSame(
            [
                'Created app/Seed/PlanTiersSeeder.php (App\Seed\PlanTiersSeeder)',
                'Next: list it in a set in config/seeds.php, then see what it would change with php xpsr seed --dry-run:',
                '    \'sets\' => [\'reference\' => [\App\Seed\PlanTiersSeeder::class]],',
            ],
            self::lines($made),
        );
        $source = (string) file_get_contents($project->root . '/app/Seed/PlanTiersSeeder.php');
        self::assertStringContainsString('namespace App\Seed;', $source);
        self::assertStringContainsString('final class PlanTiersSeeder implements SeederInterface', $source);
        self::assertStringContainsString("\$seed->table('plan_tiers')->sync('slug', [\n            // ['slug' => ..., 'name' => ...],\n        ]);", $source);
        self::assertNotEmpty(token_get_all($source, TOKEN_PARSE), 'The file is valid PHP.');

        $keyed = $this->command(MakeSeederCommand::class, ['name' => 'Billing/PricesSeeder', '--table' => 'plan_prices', '--key' => 'plan, currency']);
        self::assertSame(Command::SUCCESS, $keyed->getStatusCode(), $keyed->getErrorOutput());
        $source = (string) file_get_contents($project->root . '/app/Seed/Billing/PricesSeeder.php');
        self::assertStringContainsString('namespace App\Seed\Billing;', $source);
        self::assertStringContainsString("\$seed->table('plan_prices')->sync(['plan', 'currency'], [\n            // ['plan' => ..., 'currency' => ..., 'name' => ...],", $source);

        $again = $this->command(MakeSeederCommand::class, ['name' => 'PlanTiersSeeder']);
        self::assertSame(Command::FAILURE, $again->getStatusCode());
        self::assertStringContainsString('app/Seed/PlanTiersSeeder.php already exists. Use --force to replace it.', self::flat($again->getErrorOutput()));
        self::assertSame(Command::SUCCESS, $this->command(MakeSeederCommand::class, ['name' => 'PlanTiersSeeder', '--force' => true])->getStatusCode());
    }

    public function testMakeSeederDumpsTheRowsATableHolds(): void
    {
        $project = $this->project();
        $roles = Tables::name('roles');
        $project->runner()->run(['demo']);
        $before = $project->rows('roles', 'slug');
        $class = 'RolesDump' . ucfirst(Tables::$suffix);

        // By a natural key: the ID the database counts is left out.
        $made = $this->command(MakeSeederCommand::class, ['name' => $class, '--table' => $roles, '--key' => 'slug', '--dump' => true]);
        self::assertSame(Command::SUCCESS, $made->getStatusCode(), $made->getErrorOutput());
        self::assertContains("Wrote 3 row(s) of {$roles}.", self::lines($made));
        $source = (string) file_get_contents($project->root . "/app/Seed/{$class}.php");
        self::assertStringContainsString("\$seed->table('{$roles}')->sync('slug', [\n            ['slug' => 'admin', 'name' => 'Administrator', 'level' => 10, 'active' => true, ", $source);
        self::assertStringContainsString("'starts_at' => '2026-01-01 00:00:00'],\n", $source);
        self::assertStringContainsString("['slug' => 'guest', 'name' => 'Guest', 'level' => 0, 'active' => false, 'meta' => null, 'fee' => null, 'starts_at' => null],\n", $source);
        self::assertStringNotContainsString("'id' =>", $source);

        // The seeder it wrote describes the table as it is, and fills an empty one the same way.
        require $project->root . "/app/Seed/{$class}.php";
        self::assertFalse($project->runner()->runSeeders(["App\\Seed\\{$class}"])->changed());
        $project->db()->executeStatement('DELETE FROM ' . Tables::name('role_user'));
        $project->db()->executeStatement("DELETE FROM {$roles}");
        self::assertSame(3, $project->runner()->runSeeders(["App\\Seed\\{$class}"])->inserted());
        $strip = static fn(array $rows): array => array_map(static fn(array $row): array => array_diff_key($row, ['id' => 0]), $rows);
        self::assertEquals($strip($before), $strip($project->rows('roles', 'slug')));

        // Without --key, rows are matched by the primary key, so they keep their IDs.
        $byId = $this->command(MakeSeederCommand::class, ['name' => "{$class}ById", '--table' => $roles, '--dump' => true, '--except' => 'meta, fee,starts_at']);
        self::assertSame(Command::SUCCESS, $byId->getStatusCode(), $byId->getErrorOutput());
        $source = (string) file_get_contents($project->root . "/app/Seed/{$class}ById.php");
        self::assertMatchesRegularExpression("/->sync\\('id', \\[\n {12}\\['id' => \\d+, 'slug' => '\\w+', 'name' => '\\w+', 'level' => \\d+, 'active' => (true|false)\\],\n/", $source);
        self::assertStringNotContainsString('starts_at', $source);

        // A table of another connection, with reserved words for column names.
        $project->db('reports')->executeStatement('CREATE TABLE "order" ("key" TEXT PRIMARY KEY, "group" TEXT, photo BLOB)');
        $project->db('reports')->insert('"order"', ['"key"' => 'it\'s', '"group"' => null, 'photo' => 'binary']);
        $other = $this->command(MakeSeederCommand::class, ['name' => "{$class}Order", '--table' => 'order', '--dump' => true, '--connection' => 'reports']);
        self::assertSame(Command::SUCCESS, $other->getStatusCode(), $other->getErrorOutput());
        self::assertStringContainsString("->sync('key', [\n            ['key' => 'it\\'s', 'group' => null", (string) file_get_contents($project->root . "/app/Seed/{$class}Order.php"));
    }

    public function testMakeSeederRefusesADumpItCannotWrite(): void
    {
        $project = $this->project();

        $tableless = $this->command(MakeSeederCommand::class, ['name' => 'RolesSeeder', '--dump' => true]);
        self::assertSame(Command::INVALID, $tableless->getStatusCode());
        self::assertStringContainsString('--dump needs --table: the table whose rows to write.', self::flat($tableless->getErrorOutput()));

        $missing = $this->command(MakeSeederCommand::class, ['name' => 'RolesSeeder', '--table' => 'nowhere', '--dump' => true]);
        self::assertSame(Command::INVALID, $missing->getStatusCode());
        self::assertStringContainsString('There is no table "nowhere" to seed.', self::flat($missing->getErrorOutput()));

        $project->db('reports')->executeStatement('CREATE TABLE notes (body TEXT)');
        $keyless = $this->command(MakeSeederCommand::class, ['name' => 'NotesSeeder', '--table' => 'notes', '--dump' => true, '--connection' => 'reports']);
        self::assertSame(Command::INVALID, $keyless->getStatusCode());
        self::assertStringContainsString('--key is needed: the primary key of notes is not known.', self::flat($keyless->getErrorOutput()));

        $project->db('reports')->executeStatement('INSERT INTO events (name) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 1001) SELECT \'event \' || i FROM n');
        $large = $this->command(MakeSeederCommand::class, ['name' => 'EventsSeeder', '--table' => 'events', '--dump' => true, '--connection' => 'reports']);
        self::assertSame(Command::INVALID, $large->getStatusCode());
        self::assertStringContainsString('events has 1001 rows. --dump is for small tables of reference data, up to 1000 rows.', self::flat($large->getErrorOutput()));
        self::assertDirectoryDoesNotExist($project->root . '/app/Seed');
    }

    /** @param array<string, mixed> $seeds */
    private function project(array $seeds = Project::SEEDS, string $environment = 'local'): Project
    {
        return $this->project = (new Project($seeds, $environment))->boot();
    }

    /**
     * @param class-string<Command> $command
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     */
    private function command(string $command, array $input = [], array $options = []): CommandTester
    {
        self::assertNotNull($this->project);
        $tester = new CommandTester($this->project->application->get($command));
        $tester->execute($input, $options + ['interactive' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }

    /** @return list<string> the lines a command printed */
    private static function lines(CommandTester $tester): array
    {
        return explode("\n", rtrim($tester->getDisplay(true)));
    }

    /** Text with its line breaks and runs of spaces as single spaces: error blocks wrap, and tables pad. */
    private static function flat(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
