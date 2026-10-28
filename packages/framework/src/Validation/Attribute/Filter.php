<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Attribute;

use Attribute;

/**
 * Applies a custom filter: a service implementing Crystoline\Xpsr\Validation\FilterInterface,
 * built through the container, so it can have dependencies. #[Filter(PhoneNumber::class)].
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Filter
{
    /** @param string $service a service ID or class implementing FilterInterface */
    public function __construct(public readonly string $service) {}
}
