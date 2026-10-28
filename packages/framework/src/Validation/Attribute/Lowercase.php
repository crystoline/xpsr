<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;

/** Lowercases a string, including non-ASCII letters (UTF-8). */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Lowercase extends SwitchableFilter
{
    public function filter(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower($value, 'UTF-8') : $value;
    }
}
