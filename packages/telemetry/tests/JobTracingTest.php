<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests;

use Crystoline\Xpsr\Telemetry\Queue\TraceContextStamp;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\FailingJob;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Project;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\RecordJob;
use Crystoline\Xpsr\Telemetry\Tracer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class JobTracingTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAJobRunsInTheTraceThatQueuedItWithProducerAndConsumerSpans(): void
    {
        self::assertSame(202, $this->project->request('POST', '/queue')->getStatusCode());
        $spans = $this->project->spans();
        $request = $spans->assertSpan('POST /queue');
        $send = $spans->assertSpan('send default');
        $job = $spans->assertSpan('job ' . RecordJob::class);
        self::assertSame('producer', $send->kind());
        self::assertSame('consumer', $job->kind());
        self::assertSame($request->spanId(), $send->parentSpanId());
        self::assertSame($send->spanId(), $job->parentSpanId(), 'On sync://, the job runs inside the dispatch.');
        self::assertSame($request->traceId(), $job->traceId());
        self::assertSame('xpsr-queue', $job->attribute('messaging.system'));
        self::assertSame('process', $job->attribute('messaging.operation.type'));
        self::assertSame('send', $send->attribute('messaging.operation.type'));
        self::assertSame('default', $job->attribute('messaging.destination.name'));
        self::assertSame(RecordJob::class, $job->attribute('xpsr.job'));
        self::assertSame(['receipt', 'trace ' . $request->traceId()], $this->project->recorder->done, 'The job sees the trace.');
        $line = $this->project->logLines()[0] ?? [];
        self::assertSame('Job receipt ran', $line['message'] ?? null);
        self::assertSame($job->spanId(), $line['extra']['span_id'] ?? null, 'The job\'s log lines carry its span.');
    }

    public function testAFailingJobFailsItsSpan(): void
    {
        self::assertSame(500, $this->project->request('POST', '/queue-failing')->getStatusCode(), 'On sync://, the failure reaches the dispatcher.');
        $spans = $this->project->spans();
        $job = $spans->assertSpan('job ' . FailingJob::class);
        self::assertTrue($job->isError());
        self::assertSame(\RuntimeException::class, $job->exception());
        self::assertSame('Broken.', $job->error());
        self::assertTrue($spans->assertSpan('send default')->isError());
        self::assertTrue($spans->assertSpan('POST /queue-failing')->isError());
    }

    public function testAJobReceivedByAWorkerContinuesTheStampedTraceOrStartsItsOwn(): void
    {
        $app = $this->project->application;
        $bus = $app->get(MessageBusInterface::class);
        $tracer = $app->get(Tracer::class);

        // As a worker would: the envelope comes back from a transport with the stamps it was sent with.
        $tracer->span('worker loop', static function () use ($bus): void {
            $bus->dispatch((new Envelope(new RecordJob('stamped')))->with(new ReceivedStamp('db'), new TraceContextStamp(['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])));
            $bus->dispatch((new Envelope(new RecordJob('bare')))->with(new ReceivedStamp('db')));
        });
        $spans = $this->project->spans();
        $loop = $spans->assertSpan('worker loop');
        [$stamped, $bare] = $spans->find('job ' . RecordJob::class);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $stamped->traceId(), 'The dispatcher\'s trace.');
        self::assertSame('b7ad6b7169203331', $stamped->parentSpanId());
        self::assertSame('db', $stamped->attribute('messaging.destination.name'));
        self::assertTrue($bare->isRoot(), 'Without a stamp, a trace of its own rather than the worker\'s.');
        self::assertNotSame($loop->traceId(), $bare->traceId());
        self::assertSame(['stamped', 'trace 0af7651916cd43dd8448eb211c80319c', 'bare', 'trace ' . $bare->traceId()], $this->project->recorder->done);
    }
}
