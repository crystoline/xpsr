<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeedException;
use Crystoline\Xpsr\Database\Seeder\SeederReport;
use Crystoline\Xpsr\Database\Seeder\SeedReport;
use Crystoline\Xpsr\Database\Seeder\SeedSet;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Project;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\FailingSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\MembersSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\ModelSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\RolesSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\SampleSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\SettingsSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Sets, their order and environments, the run's transaction, the dry run, and sample values. */
final class SeedRunnerTest extends TestCase
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

    public function testTheDefaultSetIsTheFirstAndTheContainerBuildsItsSeeders(): void
    {
        $project = $this->project();
        $report = $project->runner()->run();

        self::assertSame([['reference', RolesSeeder::class], ['reference', SettingsSeeder::class]], self::ran($report));
        self::assertSame(5, $report->inserted());
        self::assertFalse($report->dryRun);
        self::assertSame('local', $project->rows('settings', 'key')[1]['value'], 'The seeder\'s constructor got the configuration.');
        self::assertSame([], $project->rows('role_user', 'user_id'));
        self::assertFalse($project->runner()->run()->changed());

        $this->project?->remove();
        $named = $this->project(['default' => 'demo'] + Project::SEEDS);
        self::assertSame('demo', $named->runner()->options()->defaultSet()->name);
        self::assertSame(8, $named->runner()->run()->inserted());
    }

    public function testASetRunsAfterTheSetsItRequires(): void
    {
        $project = $this->project();
        $runner = $project->runner();
        self::assertSame(['reference', 'demo'], array_map(static fn(SeedSet $set): string => $set->name, $runner->resolve(['demo'])));

        $report = $runner->run(['demo']);
        self::assertSame(
            [['reference', RolesSeeder::class], ['reference', SettingsSeeder::class], ['demo', MembersSeeder::class]],
            self::ran($report),
        );
        self::assertSame(3, $report->table(Tables::name('role_user'))->inserted);
        $roles = array_column($project->rows('roles', 'slug'), 'id', 'slug');
        self::assertEquals(
            [[$roles['admin'], 1], [$roles['member'], 2], [$roles['member'], 3]],
            array_map(static fn(array $row): array => [$row['role_id'], $row['user_id']], $project->rows('role_user', 'user_id')),
            'The sample rows point at the reference rows, found by their key.',
        );

        // Named in any order, and more than once, each set and each seeder runs once.
        $again = $runner->run(['demo', 'reference', 'demo']);
        self::assertCount(3, $again->seeders());
        self::assertFalse($again->changed());
        self::assertSame(8, $again->unchanged());
    }

    public function testASetIsRefusedOutsideItsEnvironments(): void
    {
        $project = $this->project(environment: 'production');
        $runner = $project->runner();
        self::assertSame('production', $runner->environment());

        try {
            $runner->run(['demo']);
            self::fail('The demo set ran in production.');
        } catch (SeedException $exception) {
            self::assertSame('The "demo" set is for local, testing (seeds.sets.demo.environments), and APP_ENV is production.', $exception->getMessage());
        }
        self::assertSame([], $project->rows('roles', 'slug'), 'The set it requires did not run either.');

        self::assertSame(5, $runner->run(['reference'])->inserted(), 'A set without environments runs everywhere.');
    }

    public function testADryRunReportsWhatWouldChangeAndChangesNothing(): void
    {
        $project = $this->project();
        $report = $project->runner()->run(['demo'], dryRun: true);
        self::assertTrue($report->dryRun);
        self::assertSame(8, $report->inserted());
        self::assertSame(['+ slug=admin', '+ slug=member', '+ slug=guest'], $report->table(Tables::name('roles'))->changes);
        self::assertSame([], $project->rows('roles', 'slug'));
        self::assertSame([], $project->rows('settings', 'key'));
        self::assertSame([], $project->rows('role_user', 'user_id'));

        // Every connection the run wrote to is rolled back, and the seeder can tell.
        $dry = null;
        $other = $project->seed(static function (Seed $seed) use (&$dry): void {
            $dry = $seed->isDryRun();
            $seed->table('events', 'reports')->sync('name', [['name' => 'launch']]);
            $seed->connection('reports')->insert('events', ['name' => 'by hand']);
        }, dryRun: true);
        self::assertTrue($dry);
        self::assertSame(1, $other->table('reports:events')->inserted);
        self::assertSame([], $project->rows('events', 'name', 'reports'));
        self::assertFalse($project->db()->isTransactionActive());
        self::assertFalse($project->db('reports')->isTransactionActive());
    }

    public function testAFailingSeederRollsTheWholeRunBack(): void
    {
        $project = $this->project(['sets' => ['reference' => [RolesSeeder::class, FailingSeeder::class, SettingsSeeder::class]]]);

        try {
            $project->runner()->run();
            self::fail('The run did not fail.');
        } catch (SeedException $exception) {
            self::assertSame(FailingSeeder::class . ' failed, and nothing was changed: The price list could not be read.', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
        self::assertSame([], $project->rows('roles', 'slug'), 'What the seeder before it wrote is gone.');
        self::assertSame([], $project->rows('settings', 'key'), 'So is what the failing seeder wrote.');
        self::assertFalse($project->db()->isTransactionActive());
    }

    public function testARunIsOneTransactionOnEachConnection(): void
    {
        $project = $this->project();
        $levels = [];
        $environment = null;
        $report = $project->seed(static function (Seed $seed) use (&$levels, &$environment): void {
            $environment = $seed->environment();
            $levels[] = $seed->connection()->getTransactionNestingLevel();
            $seed->table('events', 'reports')->sync('name', [['name' => 'launch'], ['name' => 'renewal']]);
            $levels[] = $seed->connection('reports')->getTransactionNestingLevel();
            $seed->connection('reports')->insert('events', ['name' => 'by hand']);
            // A transaction the seeder commits is part of the run; one it leaves open is rolled back.
            $seed->connection('reports')->transactional(static fn(Connection $db): int|string => $db->insert('events', ['name' => 'nested']));
            $seed->connection('reports')->beginTransaction();
            $seed->connection('reports')->insert('events', ['name' => 'left open']);
        });

        self::assertSame('local', $environment);
        self::assertSame([1, 1], $levels);
        self::assertSame(2, $report->table('reports:events')->inserted);
        self::assertSame(['by hand', 'launch', 'nested', 'renewal'], array_column($project->rows('events', 'name', 'reports'), 'name'));
        self::assertFalse($project->db('reports')->isTransactionActive());
    }

    public function testWhatASeederWritesThroughAnOrmIsInTheRunsTransaction(): void
    {
        $project = $this->project(['sets' => ['reference' => [ModelSeeder::class]]], registries: [EloquentServiceRegistry::class]);

        $dry = $project->runner()->run(dryRun: true);
        self::assertSame(1, $dry->inserted(), 'The report counts what goes through Seed::table().');
        self::assertSame([], $project->rows('roles', 'slug'), 'The model\'s row was rolled back with the rest.');

        $project->runner()->run();
        self::assertSame(['admin', 'owner'], $project->column('roles', 'slug'));
        self::assertFalse($project->runner()->run()->changed());
        self::assertCount(2, $project->rows('roles', 'slug'), 'A second run adds nothing.');
        self::assertFalse($project->db()->isTransactionActive());
    }

    public function testOnlyRunsOneSeederOfTheSets(): void
    {
        $project = $this->project();
        $runner = $project->runner();

        self::assertSame([['reference', SettingsSeeder::class]], self::ran($runner->run(only: 'SettingsSeeder')));
        self::assertSame([], $project->rows('roles', 'slug'));
        self::assertSame([['reference', RolesSeeder::class]], self::ran($runner->run(['demo'], only: '\\' . RolesSeeder::class)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No seeder of the sets is named "MembersSeeder". Their seeders: ' . RolesSeeder::class . ', ' . SettingsSeeder::class . '.');
        $runner->run(only: 'MembersSeeder');
    }

    public function testSeederClassesRunWithoutASet(): void
    {
        $project = $this->project(['sets' => []]);
        $report = $project->runner()->runSeeders(['\\' . RolesSeeder::class, SettingsSeeder::class]);
        self::assertSame([[null, RolesSeeder::class], [null, SettingsSeeder::class]], self::ran($report));
        self::assertSame(5, $report->inserted());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('config/seeds.php defines no sets.');
        $project->runner()->run();
    }

    public function testMistakesInSetsAreNamed(): void
    {
        $messages = [];
        foreach ([
            'unknown set' => [Project::SEEDS, ['staging']],
            'missing class' => [['sets' => ['reference' => ['App\Seed\MissingSeeder']]], []],
            'not a seeder' => [['sets' => ['reference' => [Project::class]]], []],
            'circle' => [['sets' => ['one' => ['requires' => ['two'], 'seeders' => []], 'two' => ['requires' => ['three']], 'three' => ['requires' => ['one']]]], ['one']],
        ] as $case => [$seeds, $sets]) {
            $project = $this->project($seeds);
            try {
                $project->runner()->run($sets);
                $messages[$case] = 'ran';
            } catch (InvalidArgumentException $exception) {
                $messages[$case] = $exception->getMessage();
            }
            $project->remove();
            $this->project = null;
        }

        self::assertSame([
            'unknown set' => 'There is no seed set "staging". The sets in config/seeds.php: reference, demo.',
            'missing class' => 'seeds.sets.reference lists App\Seed\MissingSeeder, which does not exist.',
            'not a seeder' => 'seeds.sets.reference lists ' . Project::class . ', which does not implement Crystoline\Xpsr\Database\Seeder\SeederInterface.',
            'circle' => 'The sets of seeds.sets require each other in a circle: one, two, three, one.',
        ], $messages);
    }

    public function testSampleValuesAreTheSameOnEveryRunAndDifferBySeederAndBySeed(): void
    {
        $draw = function (array $seeds): array {
            $project = $this->project($seeds);
            $project->runner()->run();
            $drawn = [SampleSeeder::$numbers, SampleSeeder::$name];
            $other = [];
            $project->seed(static function (Seed $seed) use (&$other): void {
                $other = $seed->times(5, static fn(): int => $seed->random()->getInt(1, 1_000_000));
            });
            $project->remove();
            $this->project = null;

            return [...$drawn, $other];
        };
        $sets = ['sets' => ['demo' => [SampleSeeder::class]]];

        [$numbers, $name, $other] = $draw($sets);
        self::assertCount(5, array_unique($numbers));
        self::assertMatchesRegularExpression('/\A.+ \/ .+\z/', $name, 'Faker gave a name and a French city.');
        self::assertNotSame($numbers, $other, 'Each seeder draws its own values.');

        self::assertSame([$numbers, $name, $other], $draw($sets), 'A second run, in a new application, draws the same.');

        [$reseeded, $renamed] = $draw($sets + ['random_seed' => 'another']);
        self::assertNotSame($numbers, $reseeded);
        self::assertNotSame($name, $renamed);
    }

    /**
     * @param array<string, mixed> $seeds
     * @param list<class-string> $registries
     */
    private function project(array $seeds = Project::SEEDS, string $environment = 'local', array $registries = []): Project
    {
        return $this->project = (new Project($seeds, $environment, $registries))->boot();
    }

    /** @return list<array{string|null, string}> the set and the class of each seeder that ran */
    private static function ran(SeedReport $report): array
    {
        return array_map(static fn(SeederReport $seeder): array => [$seeder->set, $seeder->seeder], $report->seeders());
    }
}
