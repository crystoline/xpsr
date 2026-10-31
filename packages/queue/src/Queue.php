<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use Crystoline\Xpsr\Database\Transactions;
use Crystoline\Xpsr\Queue\Attribute\AfterCommit;
use Crystoline\Xpsr\Queue\Attribute\OnQueue;
use Crystoline\Xpsr\Queue\Failed\FailedJobListener;
use Crystoline\Xpsr\Queue\Messenger\JobRetry;
use Crystoline\Xpsr\Queue\Messenger\RequestIds;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\Messenger\UniqueJobs;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * The queue, on Symfony Messenger: dispatch() sends a job to its transport. On sync:// it runs at
 * once and its own exception reaches the caller, unwrapped. A #[Unique] job is dropped while an
 * identical one is queued or running, and an #[AfterCommit] job dispatched inside a database
 * transaction is sent once that transaction commits.
 */
final class Queue implements QueueInterface
{
    private readonly UniqueJobs $unique;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Transports $transports,
        private readonly string $default,
        ?UniqueJobs $unique = null,
        private readonly ?RequestIds $requestIds = null,
        private readonly ?Transactions $transactions = null,
        private readonly ?JobRunner $runner = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->unique = $unique ?? new UniqueJobs();
    }

    public function dispatch(JobInterface $job, string|int|null $delay = null, ?string $queue = null): void
    {
        $queue ??= self::onQueue($job) ?? $this->default;
        if (!$this->transports->has($queue)) {
            throw new InvalidArgumentException(sprintf('There is no transport "%s" in queue.transports. Configured transports: %s.', $queue, implode(', ', $this->transports->names())));
        }
        // A mistake in #[Retry] shows where the job is dispatched, not in the worker.
        JobRetry::of($job::class);
        if ($this->transactions !== null && self::afterCommit($job) && $this->transactions->inTransaction()) {
            $this->transactions->afterCommit(fn() => $this->send($job, $delay, $queue));

            return;
        }
        $this->send($job, $delay, $queue);
    }

    private function send(JobInterface $job, string|int|null $delay, string $queue): void
    {
        $stamps = [new TransportNamesStamp([$queue])];
        if ($delay !== null) {
            $stamps[] = new DelayStamp(QueueOptions::milliseconds($delay, 'The delay'));
        }
        $requestId = $this->requestIds?->stamp();
        if ($requestId !== null) {
            $stamps[] = $requestId;
        }
        $unique = $this->unique->acquire($job, $this->transports->isSync($queue));
        if ($unique === false) {
            return;
        }
        if ($unique !== null) {
            $stamps[] = $unique;
        }
        try {
            $this->bus->dispatch($job, $stamps);
        } catch (Throwable $exception) {
            // Not queued, or failed on sync://: an identical job may be dispatched now.
            if ($unique !== null) {
                $this->unique->release($unique, $job::class);
            }
            if ($exception instanceof HandlerFailedException) {
                // The job ran at once, on sync://, and failed: there are no retries, so this was its last attempt.
                $exception = FailedJobListener::unwrap($exception);
                if ($this->runner !== null) {
                    FailedJobListener::notify($this->runner, $job, $exception, $this->logger);
                }
            }
            throw $exception;
        }
    }

    private static function onQueue(JobInterface $job): ?string
    {
        $attribute = (new ReflectionClass($job))->getAttributes(OnQueue::class)[0] ?? null;

        return $attribute?->newInstance()->transport;
    }

    private static function afterCommit(JobInterface $job): bool
    {
        return (new ReflectionClass($job))->getAttributes(AfterCommit::class) !== [];
    }
}
