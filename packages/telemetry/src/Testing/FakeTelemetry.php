<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Testing;

use Closure;
use Crystoline\Xpsr\Telemetry\Telemetry;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * The spans a test's application finished, for assertions. It reads the "memory" exporter, so
 * the test environment needs telemetry on with that exporter, in phpunit.xml:
 *
 *     <env name="OTEL_ENABLED" value="true"/>
 *     <env name="OTEL_TRACES_EXPORTER" value="memory"/>
 *
 * Then, after a request, job, or command:
 *
 *     $spans = $this->app->get(FakeTelemetry::class);
 *     $span = $spans->assertSpan('GET /payments/{id}', fn(FinishedSpan $span) => $span->attribute('http.response.status_code') === 200);
 *     $spans->assertNoSpan('job App\Jobs\SendReceipt');
 */
final class FakeTelemetry
{
    public function __construct(private readonly Telemetry $telemetry) {}

    /** @return list<FinishedSpan> the finished spans, in the order they ended */
    public function spans(): array
    {
        $options = $this->telemetry->options();
        if (!$options->enabled || $options->exporter !== 'memory') {
            throw new LogicException('FakeTelemetry reads the memory exporter: set OTEL_ENABLED=true and OTEL_TRACES_EXPORTER=memory for tests, in phpunit.xml.');
        }
        $this->telemetry->provider();
        $this->telemetry->flush();
        $memory = $this->telemetry->memory() ?? throw new LogicException('The memory exporter is not in use.');

        return array_map(static fn($span): FinishedSpan => new FinishedSpan($span), array_values($memory->getSpans()));
    }

    /**
     * The finished spans with this name that pass the condition.
     *
     * @param (Closure(FinishedSpan): bool)|null $condition
     * @return list<FinishedSpan>
     */
    public function find(string $name, ?Closure $condition = null): array
    {
        return array_values(array_filter($this->spans(), static fn(FinishedSpan $span): bool => $span->name() === $name && ($condition === null || $condition($span))));
    }

    /**
     * Asserts a span with this name finished, passing the condition when given, and returns the
     * first such span. With $count, asserts that many did.
     *
     * @param (Closure(FinishedSpan): bool)|null $condition
     */
    public function assertSpan(string $name, ?Closure $condition = null, ?int $count = null): FinishedSpan
    {
        $matching = $this->find($name, $condition);
        $message = sprintf('No "%s" span%s finished. Finished spans: %s.', $name, $condition === null ? '' : ' passing the condition', $this->names());
        Assert::assertNotSame([], $matching, $message);
        if ($count !== null) {
            Assert::assertCount($count, $matching, sprintf('%d "%s" span(s)%s finished, not %d. Finished spans: %s.', count($matching), $name, $condition === null ? '' : ' passing the condition', $count, $this->names()));
        }

        return $matching[0];
    }

    /** @param (Closure(FinishedSpan): bool)|null $condition */
    public function assertNoSpan(string $name, ?Closure $condition = null): void
    {
        Assert::assertSame([], $this->find($name, $condition), sprintf('A "%s" span%s finished.', $name, $condition === null ? '' : ' passing the condition'));
    }

    public function assertNothingTraced(): void
    {
        Assert::assertSame([], $this->spans(), 'Spans finished: ' . $this->names() . '.');
    }

    /** Forgets the spans finished so far. */
    public function clear(): void
    {
        $this->spans();
        $this->telemetry->memory()?->getStorage()->exchangeArray([]);
    }

    private function names(): string
    {
        $names = array_map(static fn(FinishedSpan $span): string => $span->name(), $this->spans());

        return $names === [] ? 'none' : implode(', ', $names);
    }
}
