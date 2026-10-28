<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;
use InvalidArgumentException;

/** The name of a field in the request when it differs from the property: #[Field('due_at')]. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(public readonly string $name)
    {
        if ($name === '' || str_contains($name, '.') || str_contains($name, '[')) {
            throw new InvalidArgumentException("#[Field] needs a plain field name, not \"{$name}\".");
        }
    }
}
