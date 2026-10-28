<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Crystoline\Xpsr\Validation\FilterInterface;

/**
 * A built-in filter that can be switched off on one field, overriding a class default:
 * #[Input(filters: [Trim::class])] with #[Trim(false)] on a password.
 */
abstract class SwitchableFilter implements FilterInterface
{
    public function __construct(public readonly bool $enabled = true) {}
}
