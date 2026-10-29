<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Failed;

use Crystoline\Xpsr\Queue\JobInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * Logs every failed attempt, and keeps a job that will not be retried again in the failed jobs
 * table (when there is one).
 *
 * @internal Subscribed by queue:work.
 */
final class FailedJobListener implements EventSubscriberInterface
{
    public function __construct(private readonly ?FailedJobStore $store, private readonly ?LoggerInterface $logger) {}

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
        if ($this->store !== null && $job instanceof JobInterface) {
            $this->store->add($event->getReceiverName(), $job, $error);
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
