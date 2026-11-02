<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

/**
 * A field that is left out of the output: what when(), whenNotNull(), and whenLoaded() return
 * when their condition is not met. Resource::value() drops it, with its key.
 */
final class MissingValue
{
    public static function is(mixed $value): bool
    {
        return $value instanceof self;
    }
}
