<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * A span in progress, from Tracer::span() or Tracer::start(). Attributes are strings, numbers,
 * booleans, or lists of them; anything else is cast to a string. With telemetry off, every
 * method is a no-op and the IDs are null.
 */
final class Span
{
    private bool $ended = false;

    /** @internal Use Tracer. */
    public function __construct(
        private readonly SpanInterface $span,
        private readonly ?ScopeInterface $scope = null,
    ) {}

    public function setAttribute(string $name, mixed $value): self
    {
        $this->span->setAttribute($name, self::value($value));

        return $this;
    }

    /** @param iterable<string, mixed> $attributes */
    public function setAttributes(iterable $attributes): self
    {
        foreach ($attributes as $name => $value) {
            $this->setAttribute($name, $value);
        }

        return $this;
    }

    /** @param iterable<string, mixed> $attributes */
    public function addEvent(string $name, iterable $attributes = []): self
    {
        $values = [];
        foreach ($attributes as $key => $value) {
            $values[$key] = self::value($value);
        }
        $this->span->addEvent($name, $values);

        return $this;
    }

    /** Records the exception as an event and marks the span as failed. */
    public function recordException(Throwable $exception): self
    {
        $this->span->recordException($exception);
        $this->span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        $this->span->setAttribute(Attributes::ERROR_TYPE, $exception::class);

        return $this;
    }

    /** Marks the span as failed, with a reason. */
    public function setError(?string $description = null): self
    {
        $this->span->setStatus(StatusCode::STATUS_ERROR, $description);

        return $this;
    }

    public function setName(string $name): self
    {
        $this->span->updateName($name);

        return $this;
    }

    public function isRecording(): bool
    {
        return $this->span->isRecording();
    }

    /** The trace ID as 32 hex characters, or null when the span is not part of a sampled trace. */
    public function traceId(): ?string
    {
        $context = $this->span->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    /** The span ID as 16 hex characters, or null when the span is not part of a sampled trace. */
    public function spanId(): ?string
    {
        $context = $this->span->getContext();

        return $context->isValid() ? $context->getSpanId() : null;
    }

    /** Ends the span and, when it was started with Tracer::start(), makes its parent current again. */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->span->end();
        $this->scope?->detach();
    }

    /** @internal */
    public function inner(): SpanInterface
    {
        return $this->span;
    }

    private static function value(mixed $value): bool|int|float|string|array|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_values(array_map(static fn(mixed $item): bool|int|float|string|null => is_scalar($item) || $item === null ? $item : self::text($item), $value));
        }

        return self::text($value);
    }

    private static function text(mixed $value): string
    {
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? (string) $value->value : $value->name;
        }

        return get_debug_type($value);
    }
}
