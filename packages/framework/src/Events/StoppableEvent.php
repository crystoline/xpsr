<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Optional base class for events that a listener may stop. Any class implementing
 * StoppableEventInterface works the same way.
 */
abstract class StoppableEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    /** Later listeners do not receive this event. */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
