<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;
use Crystoline\Xpsr\Validation\FilterInterface;
use InvalidArgumentException;

/**
 * Settings for a whole input class:
 *
 *   naming   "exact" (default: fields are named like properties) or "snake" (dueAt is due_at)
 *   filters  filters applied to every field before its own, such as [Trim::class, EmptyToNull::class]
 *
 * A property's #[Field] overrides the naming, and #[Trim(false)] switches a default filter off.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Input
{
    /** @param list<class-string<FilterInterface>> $filters */
    public function __construct(public readonly string $naming = 'exact', public readonly array $filters = [])
    {
        if ($naming !== 'exact' && $naming !== 'snake') {
            throw new InvalidArgumentException("#[Input] naming must be \"exact\" or \"snake\", not \"{$naming}\".");
        }
    }
}
