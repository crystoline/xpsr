<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Event;

use Crystoline\Xpsr\Scheduler\Task;
use Crystoline\Xpsr\Scheduler\TaskResult;

/** Dispatched after a task that got TaskStarting ran, with its result: done or failed. */
final class TaskFinished
{
    public function __construct(public readonly Task $task, public readonly TaskResult $result) {}
}
