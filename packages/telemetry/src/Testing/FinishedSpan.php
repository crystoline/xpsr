<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Testing;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

/** A span the memory exporter kept, as FakeTelemetry gives it. */
final class FinishedSpan
{
    public function __construct(private readonly SpanDataInterface $data) {}

    public function name(): string
    {
        return $this->data->getName();
    }

    /** "server", "client", "producer", "consumer", or "internal". */
    public function kind(): string
    {
        return match ($this->data->getKind()) {
            SpanKind::KIND_SERVER => 'server',
            SpanKind::KIND_CLIENT => 'client',
            SpanKind::KIND_PRODUCER => 'producer',
            SpanKind::KIND_CONSUMER => 'consumer',
            default => 'internal',
        };
    }

    public function attribute(string $name): mixed
    {
        return $this->data->getAttributes()->get($name);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->data->getAttributes()->toArray();
    }

    public function hasAttribute(string $name): bool
    {
        return $this->data->getAttributes()->has($name);
    }

    public function isError(): bool
    {
        return $this->data->getStatus()->getCode() === StatusCode::STATUS_ERROR;
    }

    /** The error description, such as the exception message, or null when the span did not fail. */
    public function error(): ?string
    {
        return $this->isError() ? $this->data->getStatus()->getDescription() : null;
    }

    /** The class of the exception recorded on the span, or null. */
    public function exception(): ?string
    {
        foreach ($this->data->getEvents() as $event) {
            if ($event->getName() === 'exception') {
                $type = $event->getAttributes()->get('exception.type');

                return is_string($type) ? $type : null;
            }
        }

        return null;
    }

    /** @return list<string> the names of the span's events */
    public function events(): array
    {
        return array_map(static fn($event): string => $event->getName(), $this->data->getEvents());
    }

    public function traceId(): string
    {
        return $this->data->getTraceId();
    }

    public function spanId(): string
    {
        return $this->data->getSpanId();
    }

    /** The parent's span ID, or null for the root of a trace. */
    public function parentSpanId(): ?string
    {
        return $this->data->getParentContext()->isValid() ? $this->data->getParentSpanId() : null;
    }

    public function isRoot(): bool
    {
        return $this->parentSpanId() === null;
    }

    public function durationNanos(): int
    {
        return $this->data->getEndEpochNanos() - $this->data->getStartEpochNanos();
    }

    public function data(): SpanDataInterface
    {
        return $this->data;
    }
}
