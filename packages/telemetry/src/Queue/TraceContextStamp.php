<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Queue;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The trace context (the W3C traceparent and tracestate headers) of the code that dispatched a
 * job, so the job's span joins that trace. Queued jobs are stored with it, so its class name and
 * properties stay the same across versions.
 */
final class TraceContextStamp implements StampInterface
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly array $headers) {}
}
