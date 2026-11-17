<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;

/** Sample data that refers to reference data by its key, in a table with a key of two columns. */
final class MembersSeeder implements SeederInterface
{
    public function run(Seed $seed): void
    {
        $roles = $seed->table(Tables::name('roles'))->map('slug');
        $seed->table(Tables::name('role_user'))->sync(['role_id', 'user_id'], [
            ['role_id' => $roles['admin'], 'user_id' => 1, 'note' => 'founder'],
            ['role_id' => $roles['member'], 'user_id' => 2, 'note' => null],
            ['role_id' => $roles['member'], 'user_id' => 3, 'note' => null],
        ]);
    }
}
