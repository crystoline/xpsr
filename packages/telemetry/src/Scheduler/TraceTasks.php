<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Scheduler;

use Crystoline\Xpsr\Scheduler\Event\TaskFinished;
use Crystoline\Xpsr\Scheduler\Event\TaskStarting;
use Crystoline\Xpsr\Scheduler\TaskStatus;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Span;
use Crystoline\Xpsr\Telemetry\Tracer;

/**
 * A span per scheduled task that runs, "task reports:daily", failed when the task is. A
 * command task's command gets its own span inside it; a job task's job gets its own trace
 * when a worker runs it.
 *
 * @internal Listens for TaskStarting and TaskFinished when the tasks instrumentation is on.
 */
final class TraceTasks
{
    /** @var array<int, Span> by the task's object ID */
    private array $spans = [];

    public function __construct(private readonly Tracer $tracer) {}

    public function starting(TaskStarting $event): void
    {
        $this->spans[spl_object_id($event->task)] = $this->tracer->start('task ' . $event->task->description(), [
            Attributes::XPSR_TASK => $event->task->description(),
            Attributes::XPSR_TASK_KIND => $event->task->kind,
        ]);
    }

    public function finished(TaskFinished $event): void
    {
        $id = spl_object_id($event->task);
        $span = $this->spans[$id] ?? null;
        if ($span === null) {
            return;
        }
        unset($this->spans[$id]);
        $span->setAttribute(Attributes::XPSR_TASK_STATUS, strtolower($event->result->status->name));
        if ($event->result->status === TaskStatus::Failed) {
            if ($event->result->exception !== null) {
                $span->recordException($event->result->exception);
            } else {
                $span->setError($event->result->message);
            }
        }
        $span->end();
    }
}
