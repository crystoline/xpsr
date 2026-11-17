<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures;

/** The fixture tables' names: each project adds its own suffix, so tests can share a server. */
final class Tables
{
    public static string $suffix = '';

    public static function name(string $table): string
    {
        return $table . '_' . self::$suffix;
    }
}
