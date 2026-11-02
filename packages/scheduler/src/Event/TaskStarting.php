<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Event;

use Crystoline\Xpsr\Scheduler\Task;

/**
 * Dispatched through the application's PSR-14 dispatcher when a due task is about to run: its
 * conditions hold and, with withoutOverlapping(), its lock is taken. A skipped task gets no event.
 */
final class TaskStarting
{
    public function __construct(public readonly Task $task) {}
}
