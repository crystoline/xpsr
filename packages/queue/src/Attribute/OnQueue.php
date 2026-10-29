<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Attribute;

use Attribute;

/**
 * The transport a job goes to when dispatch() names none: #[OnQueue('emails')]. It must be one of
 * queue.transports.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OnQueue
{
    public function __construct(public readonly string $transport) {}
}
