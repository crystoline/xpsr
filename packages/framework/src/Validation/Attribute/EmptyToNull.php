<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;

/** Turns an empty string, as an HTML form sends for an empty field, into null. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class EmptyToNull extends SwitchableFilter
{
    public function filter(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
