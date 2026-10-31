<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Failed;

use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\JobRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * Logs every failed attempt. When a job will not be retried again, keeps it in the failed jobs
 * table (when there is one), then calls its failed() method (when it has one).
 *
 * @internal Subscribed by queue:work.
 */
final class FailedJobListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?FailedJobStore $store,
        private readonly ?LoggerInterface $logger,
        private readonly ?JobRunner $runner = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // After Messenger's retry listener (priority 100) has decided whether to retry.
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', -100]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $job = $event->getEnvelope()->getMessage();
        $error = self::unwrap($event->getThrowable());
        $context = ['job' => $job::class, 'transport' => $event->getReceiverName(), 'exception' => $error];
        if ($event->willRetry()) {
            $this->logger?->warning('Queued job {job} failed and will be retried: ' . $error->getMessage(), $context);

            return;
        }
        $this->logger?->error('Queued job {job} failed for the last time: ' . $error->getMessage(), $context);
        if (!$job instanceof JobInterface) {
            return;
        }
        $this->store?->add($event->getReceiverName(), $job, $error);
        if ($this->runner !== null) {
            self::notify($this->runner, $job, $error, $this->logger);
        }
    }

    /**
     * Calls the job's failed() method, if it has one. An exception from failed() is logged and
     * goes no further: the job has already failed, and the worker goes on.
     */
    public static function notify(JobRunner $runner, JobInterface $job, Throwable $error, ?LoggerInterface $logger): void
    {
        try {
            $runner->fail($job, $error);
        } catch (Throwable $exception) {
            $logger?->error('{job}::failed() threw: ' . $exception->getMessage(), ['job' => $job::class, 'exception' => $exception]);
        }
    }

    public static function unwrap(Throwable $error): Throwable
    {
        if ($error instanceof HandlerFailedException) {
            $wrapped = $error->getWrappedExceptions();

            return $wrapped[array_key_first($wrapped)] ?? $error;
        }

        return $error;
    }
}
