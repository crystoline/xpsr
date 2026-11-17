<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Tables;

/** Defaults: a value is written when its row is inserted, and a later change to it is kept. */
final class SettingsSeeder implements SeederInterface
{
    public function __construct(private readonly Configuration $config) {}

    public function run(Seed $seed): void
    {
        $seed->table(Tables::name('settings'))->sync('key', [
            ['key' => 'invoice.due_days', 'value' => '14', 'group' => 'billing'],
            ['key' => 'site.environment', 'value' => (string) $this->config->get('app.env'), 'group' => 'site'],
        ], insertOnly: ['value']);
    }
}
