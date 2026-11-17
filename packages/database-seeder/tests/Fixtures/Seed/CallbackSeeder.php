<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed;

use Closure;
use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;

/** A seeder whose work a test sets. */
final class CallbackSeeder implements SeederInterface
{
    /** @var (Closure(Seed): mixed)|null */
    public static ?Closure $run = null;

    public function run(Seed $seed): void
    {
        (self::$run ?? static fn(): null => null)($seed);
    }
}
