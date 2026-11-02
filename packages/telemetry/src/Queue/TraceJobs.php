<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Queue;

use Crystoline\Xpsr\Queue\Failed\FailedJobListener;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * Messenger middleware on the queue's bus. Dispatching a job gets a producer span, "send
 * default", and the job is queued with the trace context in a TraceContextStamp. Handling the
 * job, in a worker or at once on sync://, gets a consumer span, "job App\Jobs\SendReceipt",
 * in the trace that dispatched it; a job that fails marks it as failed. A job queued without a
 * context, such as by a command before telemetry was on, starts a trace of its own rather than
 * joining the worker's.
 *
 * @internal Added to Crystoline\Xpsr\Queue\BusMiddleware when the jobs instrumentation is on.
 */
final class TraceJobs implements MiddlewareInterface
{
    public const SYSTEM = 'xpsr-queue';

    public function __construct(private readonly Tracer $tracer) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $job = $envelope->getMessage();
        $received = $envelope->last(ReceivedStamp::class);
        if ($received === null) {
            $transport = $envelope->last(TransportNamesStamp::class)?->getTransportNames()[0] ?? 'default';
            $span = $this->tracer->start("send {$transport}", [
                Attributes::MESSAGING_SYSTEM => self::SYSTEM,
                Attributes::MESSAGING_OPERATION_TYPE => 'send',
                Attributes::MESSAGING_DESTINATION_NAME => $transport,
                Attributes::XPSR_JOB => $job::class,
            ], SpanKind::KIND_PRODUCER);
            try {
                $headers = [];
                $this->tracer->inject($headers);
                if ($headers !== []) {
                    $envelope = $envelope->with(new TraceContextStamp($headers));
                }

                return $stack->next()->handle($envelope, $stack);
            } catch (Throwable $exception) {
                $span->recordException($exception);
                throw $exception;
            } finally {
                $span->end();
            }
        }

        $stamp = $envelope->last(TraceContextStamp::class);
        $parent = $stamp === null ? Context::getRoot() : $this->tracer->propagator()->extract($stamp->headers, null, Context::getRoot());
        $span = $this->tracer->start('job ' . $job::class, [
            Attributes::MESSAGING_SYSTEM => self::SYSTEM,
            Attributes::MESSAGING_OPERATION_TYPE => 'process',
            Attributes::MESSAGING_DESTINATION_NAME => $envelope->last(TransportNamesStamp::class)?->getTransportNames()[0] ?? $received->getTransportName(),
            Attributes::XPSR_JOB => $job::class,
        ], SpanKind::KIND_CONSUMER, $parent);
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (Throwable $exception) {
            $span->recordException($exception instanceof HandlerFailedException ? FailedJobListener::unwrap($exception) : $exception);
            throw $exception;
        } finally {
            $span->end();
        }
    }
}
