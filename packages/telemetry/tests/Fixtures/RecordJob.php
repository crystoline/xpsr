<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests\Fixtures;

use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Telemetry\Tracer;
use Psr\Log\LoggerInterface;

final class RecordJob implements JobInterface
{
    public function __construct(public readonly string $label) {}

    public function handle(Recorder $recorder, LoggerInterface $logger, Tracer $tracer): void
    {
        $recorder->done[] = $this->label;
        $logger->info('Job {label} ran', ['label' => $this->label]);
        $recorder->done[] = 'trace ' . ($tracer->traceId() ?? 'none');
    }
}
