<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry;

use Closure;
use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Throwable;

/**
 * Spans for application code, around the OpenTelemetry tracer:
 *
 *     $tracer->span('charge.capture', function (Span $span) use ($charge) {
 *         $span->setAttribute('charge.id', $charge->id);
 *         return $this->gateway->capture($charge);
 *     });
 *
 * The span is a child of the current one (the request, job, or command), is current while the
 * closure runs, records an exception the closure throws and rethrows it, and ends when the
 * closure returns. start() gives a span to end() by hand. With telemetry off, closures still
 * run and spans do nothing.
 */
final class Tracer
{
    /** @internal Use TelemetryServiceRegistry. */
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
    ) {}

    /**
     * @template T
     * @param Closure(Span): T $work
     * @param iterable<string, mixed> $attributes
     * @param SpanKind::KIND_* $kind
     * @return T
     */
    public function span(string $name, Closure $work, iterable $attributes = [], int $kind = SpanKind::KIND_INTERNAL): mixed
    {
        $span = $this->start($name, $attributes, $kind);
        try {
            return $work($span);
        } catch (Throwable $exception) {
            $span->recordException($exception);
            throw $exception;
        } finally {
            $span->end();
        }
    }

    /**
     * Starts a span as a child of the current one, and makes it current until end().
     *
     * @param iterable<string, mixed> $attributes
     * @param SpanKind::KIND_* $kind
     */
    public function start(string $name, iterable $attributes = [], int $kind = SpanKind::KIND_INTERNAL, ?ContextInterface $parent = null, ?int $startNanos = null): Span
    {
        $builder = $this->tracer->spanBuilder($name)->setSpanKind($kind);
        if ($parent !== null) {
            $builder->setParent($parent);
        }
        if ($startNanos !== null) {
            $builder->setStartTimestamp($startNanos);
        }
        $inner = $builder->startSpan();
        $span = new Span($inner, $inner->activate());
        $span->setAttributes($attributes);

        return $span;
    }

    /** The current span, or null outside any. */
    public function current(): ?Span
    {
        $span = ApiSpan::getCurrent();

        return $span->getContext()->isValid() ? new Span($span) : null;
    }

    /** The current trace ID as 32 hex characters, or null outside a sampled trace. */
    public function traceId(): ?string
    {
        return $this->current()?->traceId();
    }

    /** The current span ID as 16 hex characters, or null outside a sampled trace. */
    public function spanId(): ?string
    {
        return $this->current()?->spanId();
    }

    /**
     * Writes the current trace context into a carrier, as the W3C traceparent and tracestate
     * headers, for a request or message that continues the trace elsewhere.
     *
     * @param array<string, string> $carrier
     */
    public function inject(array &$carrier, ?PropagationSetterInterface $setter = null): void
    {
        $this->propagator->inject($carrier, $setter);
    }

    /**
     * Reads a trace context from a carrier, such as the headers of an incoming request, to use
     * as a span's parent. Without one, the context is the current one.
     *
     * @param array<string, string|list<string>> $carrier
     */
    public function extract(array $carrier, ?PropagationGetterInterface $getter = null): ContextInterface
    {
        return $this->propagator->extract($carrier, $getter, Context::getCurrent());
    }

    /** @internal */
    public function propagator(): TextMapPropagatorInterface
    {
        return $this->propagator;
    }
}
