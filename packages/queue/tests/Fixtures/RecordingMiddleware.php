<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

use ReflectionClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Records whether it saw a job being dispatched or handled. */
final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $job = (new ReflectionClass($envelope->getMessage()))->getShortName();
        $this->recorder->done[] = ($envelope->last(ReceivedStamp::class) === null ? 'dispatch ' : 'handle ') . $job;

        return $stack->next()->handle($envelope, $stack);
    }
}
