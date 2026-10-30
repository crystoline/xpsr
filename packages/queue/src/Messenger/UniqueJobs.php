<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Closure;
use Crystoline\Xpsr\Lock\Locks;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\Stamp\UniqueStamp;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\Exception\UnserializableKeyException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;
use WeakMap;

/**
 * The locks of #[Unique] jobs. Queue::dispatch() takes a job's lock and sends the key in a
 * UniqueStamp. As middleware on the message bus, this releases the lock when the job starts
 * (until: 'processing') or when it succeeds. In a worker, as an event subscriber, it releases
 * the lock when the job fails for the last time; on sync:// there are no retries, so a failure
 * releases it at once.
 *
 * @internal Built by QueueServiceRegistry.
 */
final class UniqueJobs implements MiddlewareInterface, EventSubscriberInterface
{
    /** @var WeakMap<JobInterface, true> */
    private WeakMap $dropped;

    /** @param (Closure(): Locks)|null $locks null without crystoline/xpsr-lock */
    public function __construct(
        private readonly ?Closure $locks = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->dropped = new WeakMap();
    }

    /**
     * Takes the lock of a #[Unique] job. Returns the stamp to send it with, false when an
     * identical job holds the lock, or null when the job is not unique.
     *
     * @param bool $sync whether the job runs at once, in this process, so its key is not serialized
     */
    public function acquire(JobInterface $job, bool $sync): UniqueStamp|false|null
    {
        $unique = UniqueJob::of($job);
        if ($unique === null) {
            return null;
        }
        $locks = $this->locks($job::class);
        $key = $locks->key($unique->key);
        $lock = $locks->fromKey($key, $unique->seconds, autoRelease: false);
        if (!$lock->acquire()) {
            $this->dropped[$job] = true;
            $this->logger?->debug('Queued job {job} was not dispatched: an identical job is still queued or running.', ['job' => $job::class, 'lock' => (string) $key]);

            return false;
        }
        if (!$sync) {
            try {
                serialize($key);
            } catch (UnserializableKeyException) {
                $lock->release();
                throw new LogicException(sprintf(
                    '%s is #[Unique], and the worker releases its lock, but the lock store keeps each lock to the process that took it, as the flock store does. Set lock.store to a Redis DSN, redlock, or database.',
                    $job::class,
                ));
            }
        }

        return new UniqueStamp($key, $unique->untilProcessing);
    }

    /** Whether acquire() refused this job because an identical one held the lock. */
    public function dropped(JobInterface $job): bool
    {
        return isset($this->dropped[$job]);
    }

    /**
     * Releases a job's lock. A failure is logged, not thrown: the job is done, and the lock
     * expires on its own.
     */
    public function release(UniqueStamp $stamp, string $job): void
    {
        try {
            $this->locks($job)->fromKey($stamp->key, autoRelease: false)->release();
        } catch (\Exception $exception) {
            $this->logger?->warning('The #[Unique] lock of queued job {job} could not be released, so it lasts until it expires: ' . $exception->getMessage(), ['job' => $job, 'lock' => (string) $stamp->key, 'exception' => $exception]);
        }
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(UniqueStamp::class);
        if ($stamp === null || $envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }
        $job = $envelope->getMessage()::class;
        if ($stamp->untilProcessing) {
            $this->release($stamp, $job);

            return $stack->next()->handle($envelope, $stack);
        }
        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $exception) {
            // A worker may retry the job, so onMessageFailed() decides. On sync:// it failed for good.
            if ($envelope->last(ConsumedByWorkerStamp::class) === null) {
                $this->release($stamp, $job);
            }
            throw $exception;
        }
        $this->release($stamp, $job);

        return $envelope;
    }

    public static function getSubscribedEvents(): array
    {
        // After Messenger's retry listener (priority 100) has decided whether to retry.
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', -100]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(UniqueStamp::class);
        if ($stamp !== null && !$stamp->untilProcessing && !$event->willRetry()) {
            $this->release($stamp, $event->getEnvelope()->getMessage()::class);
        }
    }

    private function locks(string $job): Locks
    {
        if ($this->locks === null) {
            throw new LogicException("{$job} is #[Unique], which needs locks: run composer require crystoline/xpsr-lock, list Crystoline\\Xpsr\\Lock\\LockServiceRegistry in app.registry, and set lock.store to a Redis DSN, redlock, or database.");
        }

        return ($this->locks)();
    }
}
