<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures;

enum Level: int
{
    case Guest = 0;
    case Member = 1;
    case Admin = 10;
}
