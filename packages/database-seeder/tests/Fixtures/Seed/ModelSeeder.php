<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Role;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use Illuminate\Database\ConnectionResolverInterface;

/** Writes one row through an Eloquent model, in a transaction of its own, and one through the table. */
final class ModelSeeder implements SeederInterface
{
    public function __construct(private readonly ConnectionResolverInterface $eloquent) {}

    public function run(Seed $seed): void
    {
        $this->eloquent->connection()->transaction(static function (): void {
            Role::query()->updateOrCreate(['slug' => 'owner'], ['name' => 'Owner', 'level' => 20]);
        });
        $seed->table(Tables::name('roles'))->sync('slug', [['slug' => 'admin', 'name' => 'Administrator']]);
    }
}
