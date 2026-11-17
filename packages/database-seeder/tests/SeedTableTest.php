<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeedException;
use Crystoline\Xpsr\Database\Seeder\SeedReport;
use Crystoline\Xpsr\Database\Seeder\SeedTable;
use Crystoline\Xpsr\Database\Seeder\SyncResult;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Level;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Project;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\RolesSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\SettingsSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** What a seeder does to one table: sync(), insert(), whenEmpty(), count(), and map(). */
final class SeedTableTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        RolesSeeder::$rows = null;
        $this->project = (new Project())->boot();
    }

    protected function tearDown(): void
    {
        RolesSeeder::$rows = null;
        $this->project->remove();
    }

    public function testSyncInsertsWhatIsMissingAndRunningItAgainChangesNothing(): void
    {
        $roles = Tables::name('roles');
        $first = $this->project->runner()->runSeeders([RolesSeeder::class]);
        self::assertSame([3, 0, 0, 0], [$first->inserted(), $first->updated(), $first->deleted(), $first->unchanged()]);
        self::assertTrue($first->changed());
        self::assertSame(['+ slug=admin', '+ slug=member', '+ slug=guest'], $first->table($roles)->changes);
        self::assertSame('3 inserted', $first->table($roles)->summary());

        [$admin, $guest, $member] = $this->project->rows('roles', 'slug');
        self::assertSame('Administrator', $admin['name']);
        self::assertEquals(10, $admin['level'], 'A backed enum is written as its value.');
        self::assertTrue((bool) $admin['active']);
        self::assertEquals(['can' => ['all'], 'color' => 'red'], json_decode((string) $admin['meta'], true));
        self::assertSame(0.0, (float) $admin['fee']);
        self::assertStringStartsWith('2026-01-01 00:00:00', (string) $admin['starts_at']);
        self::assertSame(9.5, (float) $member['fee']);
        self::assertNull($member['meta']);
        self::assertStringStartsWith('2026-01-31 09:00:00', (string) $member['starts_at']);
        self::assertFalse((bool) $guest['active']);
        self::assertEquals(0, $guest['level']);
        self::assertNull($guest['starts_at']);

        // Booleans, JSON, decimals, and dates come back from the database in other forms, and still compare as the same.
        $second = $this->project->runner()->runSeeders([RolesSeeder::class]);
        self::assertFalse($second->changed());
        self::assertSame(3, $second->unchanged());
        self::assertSame([], $second->table($roles)->changes);
        self::assertSame('3 unchanged', $second->table($roles)->summary());
    }

    public function testSyncUpdatesOnlyTheRowsAndColumnsThatDiffer(): void
    {
        $this->project->runner()->runSeeders([RolesSeeder::class]);
        $ids = array_column($this->project->rows('roles', 'slug'), 'id', 'slug');

        RolesSeeder::$rows = [
            // The same values written another way, and a new name.
            ['slug' => 'admin', 'name' => 'Admin', 'level' => 10, 'active' => true, 'meta' => ['color' => 'red', 'can' => ['all']], 'fee' => '0', 'starts_at' => '2026-01-01 00:00:00'],
            ['slug' => 'member', 'name' => 'Member', 'active' => false, 'meta' => ['can' => []], 'fee' => '9.50', 'starts_at' => new DateTimeImmutable('2026-01-31 09:00:00')],
            ['slug' => 'editor', 'name' => 'Editor', 'level' => 5],
        ];
        $report = $this->project->runner()->runSeeders([RolesSeeder::class]);
        self::assertSame([1, 2, 0, 0], [$report->inserted(), $report->updated(), $report->deleted(), $report->unchanged()]);
        self::assertSame(
            ['~ slug=admin (name)', '~ slug=member (active, meta)', '+ slug=editor'],
            $report->table(Tables::name('roles'))->changes,
        );

        $rows = array_column($this->project->rows('roles', 'slug'), null, 'slug');
        self::assertSame(['admin', 'editor', 'guest', 'member'], array_keys($rows), 'A row that is not listed stays.');
        self::assertSame('Admin', $rows['admin']['name']);
        self::assertFalse((bool) $rows['member']['active']);
        self::assertEquals(['can' => []], json_decode((string) $rows['member']['meta'], true));
        self::assertEquals(1, $rows['member']['level'], 'A column that is not listed is left alone.');
        self::assertEquals($ids['admin'], $rows['admin']['id'], 'A row is updated in place.');

        // A value set to null is a change too.
        RolesSeeder::$rows = [['slug' => 'admin', 'meta' => null, 'fee' => null]];
        $nulled = $this->project->runner()->runSeeders([RolesSeeder::class]);
        self::assertSame(['~ slug=admin (meta, fee)'], $nulled->table(Tables::name('roles'))->changes);
        self::assertFalse($this->project->runner()->runSeeders([RolesSeeder::class])->changed());
    }

    public function testPruneDeletesTheRowsThatAreNotListed(): void
    {
        $this->project->runner()->runSeeders([RolesSeeder::class]);
        $result = null;
        $report = $this->project->seed(static function (Seed $seed) use (&$result): void {
            $result = $seed->table(Tables::name('roles'))->sync('slug', [
                ['slug' => 'admin', 'name' => 'Administrator'],
                ['slug' => 'owner', 'name' => 'Owner'],
            ], prune: true);
        });

        self::assertInstanceOf(SyncResult::class, $result);
        self::assertSame([1, 0, 1, 2], [$result->inserted, $result->updated, $result->unchanged, $result->deleted]);
        self::assertTrue($result->changed());
        self::assertSame(2, $report->deleted());
        $changes = $report->table(Tables::name('roles'))->changes;
        sort($changes);
        self::assertSame(['+ slug=owner', '- slug=guest', '- slug=member'], $changes);
        self::assertSame('1 inserted, 2 deleted, 1 unchanged', $report->table(Tables::name('roles'))->summary());
        self::assertSame(['admin', 'owner'], $this->project->column('roles', 'slug'));
    }

    public function testInsertOnlyColumnsAreWrittenOnceAndThenLeftToTheApplication(): void
    {
        $db = $this->project->db();
        $settings = Tables::name('settings');
        $this->project->runner()->runSeeders([SettingsSeeder::class]);
        self::assertSame(['invoice.due_days', 'site.environment'], $this->project->column('settings', 'key'), 'Reserved words work as column names.');

        // Someone changes a setting, and something else changes its group.
        $db->executeStatement(
            sprintf('UPDATE %s SET %s = ?, %s = ? WHERE %s = ?', $settings, $db->quoteSingleIdentifier('value'), $db->quoteSingleIdentifier('group'), $db->quoteSingleIdentifier('key')),
            ['30', 'misc', 'invoice.due_days'],
        );
        $report = $this->project->runner()->runSeeders([SettingsSeeder::class]);
        self::assertSame(['~ key=invoice.due_days (group)'], $report->table($settings)->changes);
        $row = $this->project->rows('settings', 'key')[0];
        self::assertSame('30', $row['value'], 'The changed value is kept.');
        self::assertSame('billing', $row['group']);
    }

    public function testAKeyOfSeveralColumns(): void
    {
        $sync = fn(array $rows): SeedReport => $this->project->seed(
            static fn(Seed $seed): SyncResult => $seed->table(Tables::name('role_user'))->sync(['role_id', 'user_id'], $rows),
        );
        self::assertSame(3, $sync([
            ['role_id' => 1, 'user_id' => 1, 'note' => 'a'],
            ['role_id' => 1, 'user_id' => 2, 'note' => 'b'],
            ['role_id' => 2, 'user_id' => 1, 'note' => 'c'],
        ])->inserted());

        $report = $sync([
            ['role_id' => 1, 'user_id' => 2, 'note' => 'B'],
            ['role_id' => 2, 'user_id' => 2, 'note' => 'd'],
            ['role_id' => '2', 'user_id' => '1', 'note' => 'c'],
        ]);
        self::assertSame(['~ role_id=1, user_id=2 (note)', '+ role_id=2, user_id=2'], $report->table(Tables::name('role_user'))->changes);
        self::assertSame(1, $report->unchanged(), 'A key written as a string finds the row with the number.');
        self::assertSame(['a', 'B', 'c', 'd'], array_column($this->project->db()->fetchAllAssociative('SELECT note FROM ' . Tables::name('role_user') . ' ORDER BY role_id, user_id'), 'note'));
    }

    public function testInsertWhenEmptyCountAndMap(): void
    {
        $seen = [];
        $report = $this->project->seed(static function (Seed $seed) use (&$seen): void {
            $roles = $seed->table(Tables::name('roles'));
            $seen['empty before'] = $roles->isEmpty();
            $seen['filled'] = $roles->whenEmpty(static fn(SeedTable $table): int => $table->insert($seed->times(3, static fn(int $number): array => [
                'slug' => "role-{$number}",
                'name' => "Role {$number}",
                'level' => $number,
                'active' => $number !== 3,
            ])));
            $seen['filled again'] = $roles->whenEmpty(static fn(SeedTable $table): int => $table->insert([['slug' => 'twice', 'name' => 'Twice']]));
            $seen['empty after'] = $roles->isEmpty();
            $seen['count'] = $roles->count();
            $seen['count by slug'] = $roles->count(['slug' => 'role-2']);
            $seen['count by null'] = $roles->count(['meta' => null, 'active' => true]);
            $seen['count by enum'] = $roles->count(['level' => Level::Member]);
            $seen['ids'] = $roles->map('slug');
            $seen['levels'] = $roles->map('slug', 'level');
            $seen['active'] = $roles->map('level', 'active');
        });

        self::assertTrue($seen['empty before']);
        self::assertTrue($seen['filled']);
        self::assertFalse($seen['filled again']);
        self::assertFalse($seen['empty after']);
        self::assertSame(3, $seen['count']);
        self::assertSame(1, $seen['count by slug']);
        self::assertSame(2, $seen['count by null']);
        self::assertSame(1, $seen['count by enum']);
        ksort($seen['ids']);
        ksort($seen['levels']);
        ksort($seen['active']);
        self::assertSame(['role-1', 'role-2', 'role-3'], array_keys($seen['ids']));
        self::assertContainsOnlyInt($seen['ids'], 'Without a column, map() gives the primary key.');
        self::assertSame(['role-1' => 1, 'role-2' => 2, 'role-3' => 3], $seen['levels']);
        self::assertSame([1 => true, 2 => true, 3 => false], $seen['active'], 'Values are read as the column\'s type.');
        self::assertSame(3, $report->inserted());
        self::assertSame('3 inserted, left as it is, not empty', $report->table(Tables::name('roles'))->summary());
    }

    public function testATableOfAnotherConnectionAndATableDbalCannotRead(): void
    {
        // SQLite accepts a column type DBAL does not know; the table is then seeded by PHP types.
        $this->project->db('reports')->executeStatement('CREATE TABLE places (code TEXT PRIMARY KEY, "order" INTEGER, area GEOMETRY, open BOOLEAN, tags TEXT, since TEXT)');
        $rows = [
            ['code' => 'lagos', 'order' => 1, 'area' => null, 'open' => true, 'tags' => ['port', 'city'], 'since' => new DateTimeImmutable('2026-01-31 09:00:00')],
            ['code' => 'abuja', 'order' => 2, 'area' => null, 'open' => false, 'tags' => [], 'since' => null],
        ];
        $sync = fn(array $rows, bool $prune = false): SeedReport => $this->project->seed(static function (Seed $seed) use ($rows, $prune): void {
            $seed->table('places', 'reports')->sync('code', $rows, prune: $prune);
            $seed->table('events', 'reports')->sync('name', [['name' => 'launch']]);
        });

        $first = $sync($rows);
        self::assertSame(2, $first->table('reports:places')->inserted);
        self::assertSame(1, $first->table('reports:events')->inserted);
        self::assertSame(
            [
                ['code' => 'abuja', 'order' => 2, 'area' => null, 'open' => 0, 'tags' => '[]', 'since' => null],
                ['code' => 'lagos', 'order' => 1, 'area' => null, 'open' => 1, 'tags' => '["port","city"]', 'since' => '2026-01-31 09:00:00'],
            ],
            $this->project->rows('places', 'code', 'reports'),
        );
        self::assertFalse($sync($rows)->changed());

        $rows[0]['open'] = false;
        $rows[0]['tags'] = ['city'];
        unset($rows[1]);
        $second = $sync($rows, prune: true);
        self::assertSame(['~ code=lagos (open, tags)', '- code=abuja'], $second->table('reports:places')->changes);
        self::assertSame([], $this->project->rows('roles', 'slug'), 'The default connection was not written to.');
    }

    public function testANumberInAColumnTheDatabaseReadsAsABoolean(): void
    {
        // MySQL and MariaDB have no boolean type: DBAL reads every TINYINT column as one.
        $db = $this->project->db();
        $type = $db->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'TINYINT' : 'SMALLINT';
        $table = Tables::name('priorities');
        $db->executeStatement("CREATE TABLE {$table} (code VARCHAR(20) PRIMARY KEY, weight {$type} NOT NULL)");
        try {
            $sync = fn(int $weight): SeedReport => $this->project->seed(
                static fn(Seed $seed): SyncResult => $seed->table($table)->sync('code', [['code' => 'high', 'weight' => $weight], ['code' => 'low', 'weight' => 1]]),
            );
            self::assertSame(2, $sync(5)->inserted());
            self::assertFalse($sync(5)->changed());
            self::assertSame(['~ code=high (weight)'], $sync(3)->table($table)->changes);
            self::assertEquals([3, 1], array_column($db->fetchAllAssociative("SELECT weight FROM {$table} ORDER BY code"), 'weight'));
        } finally {
            $db->executeStatement("DROP TABLE {$table}");
        }
    }

    public function testMistakesAreNamed(): void
    {
        $roles = Tables::name('roles');
        $sync = fn(array $rows, string|array $key = 'slug', array $insertOnly = []): string => $this->failure(
            static fn(Seed $seed): SyncResult => $seed->table($roles)->sync($key, $rows, $insertOnly),
        );

        self::assertSame(
            'There is no table "nowhere" to seed. Run the migrations first: php xpsr migrate.',
            $this->failure(static fn(Seed $seed): SyncResult => $seed->table('nowhere')->sync('id', [['id' => 1]])),
        );
        self::assertSame(
            "The table {$roles} has no column \"title\". Its columns: id, slug, name, level, active, meta, fee, starts_at.",
            $sync([['slug' => 'admin', 'title' => 'Administrator']]),
        );
        self::assertSame("The table {$roles} has no column \"code\". Its columns: id, slug, name, level, active, meta, fee, starts_at.", $sync([['slug' => 'admin']], 'code'));
        self::assertSame("Two rows for {$roles} have the same key: slug=admin.", $sync([['slug' => 'admin', 'name' => 'One'], ['slug' => 'admin', 'name' => 'Two']]));
        self::assertSame("A row for {$roles} has no value for its key column slug.", $sync([['name' => 'Nameless']]));
        self::assertSame("Row 1 for {$roles} must be an array of values by column name.", $sync([['slug' => 'admin', 'name' => 'One'], ['editor', 'Editor']]));
        self::assertSame("The key of {$roles} must be a column name, or a list of different column names.", $sync([['slug' => 'admin']], ['slug', 'slug']));
        self::assertSame(
            "insertOnly names columns of {$roles} that are written only when a row is inserted; it cannot name a key column.",
            $sync([['slug' => 'admin', 'name' => 'One']], 'slug', ['slug']),
        );
        self::assertSame("{$roles}.starts_at cannot take a value of type array.", $sync([['slug' => 'admin', 'name' => 'One', 'starts_at' => ['2026', '01']]]));
        self::assertSame(
            'Name the column to map ' . Tables::name('role_user') . '.note to: the table\'s primary key has several columns.',
            $this->failure(static fn(Seed $seed): array => $seed->table(Tables::name('role_user'))->map('note')),
        );
        self::assertSame('Seed::times() needs a count of 0 or more.', $this->failure(static fn(Seed $seed): array => $seed->times(-1, static fn(): array => [])));
        self::assertSame([], $this->project->rows('roles', 'slug'), 'Nothing was written.');
    }

    public function testAReportListsAHundredChangesAndCountsTheRest(): void
    {
        $report = $this->project->seed(static fn(Seed $seed): SyncResult => $seed->table(Tables::name('roles'))->sync(
            'slug',
            $seed->times(130, static fn(int $number): array => ['slug' => "role-{$number}", 'name' => "Role {$number}"]),
        ));
        $table = $report->table(Tables::name('roles'));
        self::assertSame(130, $table->inserted);
        self::assertCount(100, $table->changes);
        self::assertSame(30, $table->omitted);
        self::assertSame(130, (int) $this->project->db()->fetchOne('SELECT COUNT(*) FROM ' . Tables::name('roles')));
    }

    /** The message of what made a seeder fail. */
    private function failure(callable $work): string
    {
        try {
            $this->project->seed($work);
        } catch (SeedException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());

            return $exception->getPrevious()->getMessage();
        }
        self::fail('The seeder did not fail.');
    }
}
