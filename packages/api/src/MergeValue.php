<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

/**
 * Fields merged into the array that holds it: what mergeWhen() returns. Resource::value()
 * spreads its values into the parent, in place of the key it was under.
 *
 * @internal
 */
final class MergeValue
{
    /** @param array<array-key, mixed> $values */
    public function __construct(public readonly array $values) {}
}
