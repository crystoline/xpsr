<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;

/** Draws sample values, and keeps them for the test to compare between runs. */
final class SampleSeeder implements SeederInterface
{
    /** @var list<int> */
    public static array $numbers = [];

    public static string $name = '';

    public function run(Seed $seed): void
    {
        self::$numbers = $seed->times(5, static fn(int $number): int => $seed->random()->getInt(1, 1_000_000));
        self::$name = class_exists(\Faker\Factory::class) ? $seed->faker()->name() . ' / ' . $seed->faker('fr_FR')->city() : '';
    }
}
