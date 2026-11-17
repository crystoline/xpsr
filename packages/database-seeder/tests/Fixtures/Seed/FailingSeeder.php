<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;
use RuntimeException;

/** Writes a row, then fails. */
final class FailingSeeder implements SeederInterface
{
    public function run(Seed $seed): void
    {
        $seed->table(Tables::name('settings'))->insert([['key' => 'half.done', 'value' => 'yes']]);

        throw new RuntimeException('The price list could not be read.');
    }
}
