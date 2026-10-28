<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;
use Crystoline\Xpsr\Validation\FilterInterface;
use InvalidArgumentException;

/**
 * Replaces regular expression matches in a string, for example #[Replace('/[\s-]/', '')] to
 * drop spaces and dashes from a phone number.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Replace implements FilterInterface
{
    public function __construct(public readonly string $pattern, public readonly string $replacement = '')
    {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("#[Replace] needs a valid regular expression, not {$pattern}.");
        }
    }

    public function filter(mixed $value): mixed
    {
        return is_string($value) ? (string) preg_replace($this->pattern, $this->replacement, $value) : $value;
    }
}
