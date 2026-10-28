<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation;

/**
 * Normalizes an input value before it is converted and validated, for example by trimming
 * spaces. A filter must leave values it does not handle unchanged. Filters normalize input; they
 * are not a security measure: escape output when writing it and bind query parameters instead.
 */
interface FilterInterface
{
    public function filter(mixed $value): mixed;
}
