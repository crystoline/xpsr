<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;

/** Removes whitespace (or the given characters) from both ends of a string. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Trim extends SwitchableFilter
{
    public function __construct(bool $enabled = true, public readonly string $characters = " \t\n\r\0\x0B")
    {
        parent::__construct($enabled);
    }

    public function filter(mixed $value): mixed
    {
        return is_string($value) ? trim($value, $this->characters) : $value;
    }
}
