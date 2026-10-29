<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use Crystoline\Xpsr\Queue\Attribute\OnQueue;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The queue, on Symfony Messenger: dispatch() sends a job to its transport. On sync:// it runs at
 * once and its own exception reaches the caller, unwrapped.
 */
final class Queue implements QueueInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Transports $transports,
        private readonly string $default,
    ) {}

    public function dispatch(JobInterface $job, string|int|null $delay = null, ?string $queue = null): void
    {
        $queue ??= self::onQueue($job) ?? $this->default;
        if (!$this->transports->has($queue)) {
            throw new InvalidArgumentException(sprintf('There is no transport "%s" in queue.transports. Configured transports: %s.', $queue, implode(', ', $this->transports->names())));
        }
        $stamps = [new TransportNamesStamp([$queue])];
        if ($delay !== null) {
            $stamps[] = new DelayStamp(QueueOptions::milliseconds($delay, 'The delay'));
        }
        try {
            $this->bus->dispatch($job, $stamps);
        } catch (HandlerFailedException $exception) {
            throw $exception->getWrappedExceptions()[array_key_first($exception->getWrappedExceptions())] ?? $exception;
        }
    }

    private static function onQueue(JobInterface $job): ?string
    {
        $attribute = (new ReflectionClass($job))->getAttributes(OnQueue::class)[0] ?? null;

        return $attribute?->newInstance()->transport;
    }
}
