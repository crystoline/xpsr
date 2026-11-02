<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests;

use Crystoline\Xpsr\Telemetry\Span;
use Crystoline\Xpsr\Telemetry\Telemetry;
use Crystoline\Xpsr\Telemetry\TelemetryOptions;
use Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Currency;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Failing;
use Crystoline\Xpsr\Telemetry\Testing\FinishedSpan;
use Crystoline\Xpsr\Telemetry\Tracer;
use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TracerTest extends TestCase
{
    /** @var list<Telemetry> */
    private array $telemetries = [];

    protected function tearDown(): void
    {
        foreach ($this->telemetries as $telemetry) {
            $telemetry->shutdown();
        }
    }

    public function testSpansNestAndCarryAttributesAndTheClosuresResult(): void
    {
        [$tracer, $spans] = $this->tracer();
        self::assertNull($tracer->current());
        self::assertNull($tracer->traceId());

        $result = $tracer->span('order.place', function (Span $outer) use ($tracer): string {
            self::assertSame($outer->traceId(), $tracer->traceId());
            self::assertSame($outer->spanId(), $tracer->spanId());
            $outer->setAttributes(['currency' => Currency::Naira, 'amount' => 500, 'items' => ['a', 'b'], 'currencies' => [Currency::Naira], 'ok' => true, 'note' => null, 'object' => new \stdClass()]);
            $outer->addEvent('stock.checked', ['count' => 3]);
            $tracer->span('order.charge', static fn(Span $inner): Span => $inner->setAttribute('gateway', 'test'), ['attempt' => 1], SpanKind::KIND_CLIENT);

            return 'placed';
        });
        self::assertSame('placed', $result);
        self::assertNull($tracer->current(), 'The span is no longer current once ended.');

        $charge = $spans->assertSpan('order.charge');
        $order = $spans->assertSpan('order.place');
        self::assertSame('client', $charge->kind());
        self::assertSame('internal', $order->kind());
        self::assertSame(['attempt' => 1, 'gateway' => 'test'], $charge->attributes());
        self::assertSame($order->spanId(), $charge->parentSpanId());
        self::assertSame($order->traceId(), $charge->traceId());
        self::assertTrue($order->isRoot());
        self::assertFalse($order->isError());
        self::assertSame('NGN', $order->attribute('currency'));
        self::assertSame(['a', 'b'], $order->attribute('items'));
        self::assertSame(['NGN'], $order->attribute('currencies'));
        self::assertSame('stdClass', $order->attribute('object'));
        self::assertFalse($order->hasAttribute('note'), 'Null attributes are not kept.');
        self::assertSame(['stock.checked'], $order->events());
        self::assertGreaterThanOrEqual(0, $order->durationNanos());
    }

    public function testAnExceptionIsRecordedAndRethrown(): void
    {
        [$tracer, $spans] = $this->tracer();
        try {
            $tracer->span('risky', Failing::gatewayDown(...));
            self::fail('Not rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('Gateway down.', $exception->getMessage());
        }
        $span = $spans->assertSpan('risky', static fn(FinishedSpan $span): bool => $span->isError());
        self::assertSame('Gateway down.', $span->error());
        self::assertSame(RuntimeException::class, $span->exception());
        self::assertSame(RuntimeException::class, $span->attribute('error.type'));
    }

    public function testManualSpansAndPropagation(): void
    {
        [$tracer, $spans] = $this->tracer();
        $span = $tracer->start('import', ['file' => 'a.csv']);
        self::assertSame($span->spanId(), $tracer->spanId());
        $headers = [];
        $tracer->inject($headers);
        self::assertArrayHasKey('traceparent', $headers);
        self::assertSame("00-{$span->traceId()}-{$span->spanId()}-01", $headers['traceparent']);
        $span->end();
        $span->end();
        self::assertNull($tracer->current());

        $context = $tracer->extract(['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', ApiSpan::fromContext($context)->getContext()->getTraceId());
        $child = $tracer->start('continued', [], SpanKind::KIND_SERVER, $context);
        $child->end();
        $continued = $spans->assertSpan('continued');
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $continued->traceId());
        self::assertSame('b7ad6b7169203331', $continued->parentSpanId());
        $spans->assertSpan('import', null, 1);
    }

    public function testWithTelemetryOffSpansDoNothingAndClosuresStillRun(): void
    {
        $telemetry = new Telemetry(TelemetryOptions::from(['enabled' => false, 'exporter' => 'memory'], [], 'Shop', 'local'));
        $tracer = new Tracer($telemetry->tracer(), $telemetry->propagator());
        self::assertFalse($telemetry->enabled());
        self::assertSame('ran', $tracer->span('anything', static fn(Span $span): string => $span->setAttribute('a', 1) === $span ? 'ran' : 'no'));
        self::assertNull($tracer->current());
        $span = $tracer->start('manual');
        self::assertNull($span->traceId());
        self::assertFalse($span->isRecording());
        $span->end();
        $headers = [];
        $tracer->inject($headers);
        self::assertSame([], $headers);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OTEL_ENABLED=true and OTEL_TRACES_EXPORTER=memory');
        (new FakeTelemetry($telemetry))->spans();
    }

    public function testTheFakeExplainsWhatFinished(): void
    {
        [$tracer, $spans] = $this->tracer();
        $spans->assertNothingTraced();
        $tracer->span('a', static fn(): null => null);
        $tracer->span('b', static fn(): null => null);
        $spans->assertSpan('a');
        $spans->assertNoSpan('c');
        self::assertCount(2, $spans->spans());
        try {
            $spans->assertSpan('c');
            self::fail('Not asserted.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('No "c" span finished. Finished spans: a, b.', $failure->getMessage());
        }
        try {
            $spans->assertSpan('a', static fn(FinishedSpan $span): bool => $span->isError());
            self::fail('Not asserted.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('No "a" span passing the condition finished.', $failure->getMessage());
        }
        try {
            $spans->assertSpan('a', null, 2);
            self::fail('Not asserted.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('1 "a" span(s) finished, not 2.', $failure->getMessage());
        }
        $spans->clear();
        $spans->assertNothingTraced();
    }

    public function testBatchedSpansReachTheExporterOnFlushAndNoneWithTheNoneExporter(): void
    {
        [$tracer, $spans, $telemetry] = $this->tracer(['batch' => true]);
        $tracer->span('batched', static fn(): null => null);
        self::assertSame([], $telemetry->memory()?->getSpans() ?? [], 'Not exported yet.');
        $spans->assertSpan('batched');

        $none = new Telemetry(TelemetryOptions::from(['enabled' => true, 'exporter' => 'none', 'batch' => false], [], 'Shop', 'local'));
        $this->telemetries[] = $none;
        $tracer = new Tracer($none->tracer(), $none->propagator());
        $tracer->span('dropped', static fn(): null => null);
        self::assertNull($none->memory());
    }

    /**
     * @param array<string, mixed> $config
     * @return array{Tracer, FakeTelemetry, Telemetry}
     */
    private function tracer(array $config = []): array
    {
        $telemetry = new Telemetry(TelemetryOptions::from($config + ['enabled' => true, 'exporter' => 'memory', 'batch' => false], [], 'Shop', 'local'));
        $this->telemetries[] = $telemetry;

        return [new Tracer($telemetry->tracer(), $telemetry->propagator()), new FakeTelemetry($telemetry), $telemetry];
    }
}
