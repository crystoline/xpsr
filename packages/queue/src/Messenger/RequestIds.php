<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Closure;
use Crystoline\Xpsr\Queue\Stamp\RequestIdStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Carries the request ID of crystoline/xpsr-log-monolog to jobs. Queue::dispatch() sends the
 * current ID with a job in a RequestIdStamp. In a worker, as an event subscriber, this sets the
 * job's ID from when the job is received until the worker moves on, so the job's log lines, and
 * those about its failure or retry, share the request's ID.
 *
 * @internal Built by QueueServiceRegistry when crystoline/xpsr-log-monolog is installed.
 */
final class RequestIds implements EventSubscriberInterface
{
    private bool $changed = false;
    private ?string $previous = null;

    /**
     * @param Closure(): ?string $get the current request ID
     * @param Closure(?string): void $set
     */
    public function __construct(
        private readonly Closure $get,
        private readonly Closure $set,
    ) {}

    /** The stamp for a job dispatched now, or null outside a request. */
    public function stamp(): ?RequestIdStamp
    {
        $id = ($this->get)();

        return $id === null ? null : new RequestIdStamp($id);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before other listeners log anything about the job.
            WorkerMessageReceivedEvent::class => ['onReceived', 1000],
            // Dispatched once the job and the listeners on its success or failure are done.
            WorkerRunningEvent::class => ['onRunning', -1000],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->changed) {
            $this->previous = ($this->get)();
            $this->changed = true;
        }
        ($this->set)($event->getEnvelope()->last(RequestIdStamp::class)?->id);
    }

    public function onRunning(): void
    {
        if ($this->changed) {
            ($this->set)($this->previous);
            $this->changed = false;
        }
    }
}
