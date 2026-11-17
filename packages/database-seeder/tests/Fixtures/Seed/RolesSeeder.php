<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Level;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use DateTimeImmutable;

/** Reference data with every kind of value: an enum, booleans, JSON, a decimal, and dates. */
final class RolesSeeder implements SeederInterface
{
    /** @var list<array<string, mixed>>|null rows to seed in place of the usual ones */
    public static ?array $rows = null;

    public function run(Seed $seed): void
    {
        $seed->table(Tables::name('roles'))->sync('slug', self::$rows ?? [
            ['slug' => 'admin', 'name' => 'Administrator', 'level' => Level::Admin, 'active' => true, 'meta' => ['can' => ['all'], 'color' => 'red'], 'fee' => '0.00', 'starts_at' => new DateTimeImmutable('2026-01-01 00:00:00')],
            ['slug' => 'member', 'name' => 'Member', 'level' => 1, 'active' => true, 'meta' => null, 'fee' => 9.5, 'starts_at' => '2026-01-31 09:00:00'],
            ['slug' => 'guest', 'name' => 'Guest', 'level' => Level::Guest, 'active' => false],
        ]);
    }
}
