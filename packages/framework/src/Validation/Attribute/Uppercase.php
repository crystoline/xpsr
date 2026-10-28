<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;

/** Uppercases a string, including non-ASCII letters (UTF-8). */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Uppercase extends SwitchableFilter
{
    public function filter(mixed $value): mixed
    {
        return is_string($value) ? mb_strtoupper($value, 'UTF-8') : $value;
    }
}
