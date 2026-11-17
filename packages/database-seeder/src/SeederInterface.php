<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

/**
 * Seed data: rows a database must hold. A seeder is built by the container, so its constructor
 * takes services by type, and it is listed in a set in config/seeds.php.
 *
 * A seeder must be safe to run again. Seed::table()->sync() matches rows by a key and changes
 * only what differs, so a second run changes nothing.
 */
interface SeederInterface
{
    public function run(Seed $seed): void;
}
