<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Log;

use Crystoline\Xpsr\Telemetry\Tracer;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds "trace_id" and "span_id" to the extra data of every record logged inside a span, so a
 * log line and a trace meet.
 *
 * @internal Added to Crystoline\Xpsr\Log\Monolog\LogProcessors when telemetry is on.
 */
final class TraceContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly Tracer $tracer) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $span = $this->tracer->current();
        if ($span === null) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'trace_id' => $span->traceId(), 'span_id' => $span->spanId()]);
    }
}
