<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests;

use Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware;
use Crystoline\Xpsr\Telemetry\Http\TraceRequestsMiddleware;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Project;
use Crystoline\Xpsr\Telemetry\Testing\FinishedSpan;
use PHPUnit\Framework\TestCase;

final class RequestTracingTest extends TestCase
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

    public function testARequestIsAServerSpanNamedByItsRouteWithItsQueriesAndSpansInside(): void
    {
        $response = $this->project->request('GET', '/payments/1?expand=lines', ['User-Agent' => 'Test/1.0', 'X-Forwarded-For' => '198.51.100.7']);
        self::assertSame(200, $response->getStatusCode());
        $spans = $this->project->spans();

        $request = $spans->assertSpan('GET /payments/{id}');
        self::assertSame('server', $request->kind());
        self::assertTrue($request->isRoot());
        self::assertFalse($request->isError());
        self::assertSame('GET', $request->attribute('http.request.method'));
        self::assertSame('/payments/{id}', $request->attribute('http.route'));
        self::assertSame('payments.show', $request->attribute('xpsr.route.name'));
        self::assertSame(200, $request->attribute('http.response.status_code'));
        self::assertSame('/payments/1', $request->attribute('url.path'));
        self::assertSame('expand=lines', $request->attribute('url.query'));
        self::assertSame('http', $request->attribute('url.scheme'));
        self::assertSame('shop.example', $request->attribute('server.address'));
        self::assertSame('Test/1.0', $request->attribute('user_agent.original'));
        self::assertSame('203.0.113.9', $request->attribute('client.address'), 'X-Forwarded-For is ignored: 203.0.113.9 is not a trusted proxy.');
        self::assertSame('1.1', $request->attribute('network.protocol.version'));
        self::assertSame($response->getHeaderLine('X-Request-Id'), $request->attribute('xpsr.request_id'));

        $query = $spans->assertSpan('SELECT telemetry_payments');
        self::assertSame('client', $query->kind());
        self::assertSame($request->spanId(), $query->parentSpanId());
        self::assertSame('sqlite', $query->attribute('db.system.name'));
        self::assertSame('SELECT', $query->attribute('db.operation.name'));
        self::assertSame('telemetry_payments', $query->attribute('db.collection.name'));
        self::assertSame('SELECT * FROM telemetry_payments WHERE id = ?', $query->attribute('db.query.text'));
        self::assertSame('main', $query->attribute('xpsr.db.connection'));
        self::assertGreaterThan(0, $query->durationNanos());

        $render = $spans->assertSpan('payment.render');
        self::assertSame($request->spanId(), $render->parentSpanId());
        self::assertSame('1', $render->attribute('payment.id'));

        $line = $this->project->logLines()[0] ?? [];
        self::assertSame('Payment 1 shown', $line['message'] ?? null);
        self::assertSame($request->traceId(), $line['extra']['trace_id'] ?? null, 'Log lines carry the trace ID.');
        self::assertSame($request->spanId(), $line['extra']['span_id'] ?? null);
        self::assertSame($response->getHeaderLine('X-Request-Id'), $line['extra']['request_id'] ?? null);
    }

    public function testAnIncomingTraceparentContinuesTheTraceAndTheRequestIdIsReadInEitherOrder(): void
    {
        $this->project->remove();
        $this->project = new Project(middleware: [TraceRequestsMiddleware::class, RequestIdMiddleware::class]);
        $this->project->boot();
        $response = $this->project->request('GET', '/hello', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', 'X-Request-Id' => 'lb-42']);
        self::assertSame(200, $response->getStatusCode());
        $span = $this->project->spans()->assertSpan('GET /hello');
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $span->traceId());
        self::assertSame('b7ad6b7169203331', $span->parentSpanId());
        self::assertSame('lb-42', $span->attribute('xpsr.request_id'));
    }

    public function testErrorsAndMissingRoutes(): void
    {
        self::assertSame(500, $this->project->request('GET', '/boom')->getStatusCode());
        $boom = $this->project->spans()->assertSpan('GET /boom');
        self::assertTrue($boom->isError());
        self::assertSame(500, $boom->attribute('http.response.status_code'));
        self::assertSame(\RuntimeException::class, $boom->exception());
        self::assertSame('Boom.', $boom->error());

        self::assertSame(404, $this->project->request('GET', '/nope')->getStatusCode());
        $missing = $this->project->spans()->assertSpan('GET', static fn(FinishedSpan $span): bool => $span->attribute('url.path') === '/nope');
        self::assertFalse($missing->isError(), 'A 4xx is not a failure of the server.');
        self::assertSame(404, $missing->attribute('http.response.status_code'));
        self::assertFalse($missing->hasAttribute('http.route'));
        self::assertSame(\Crystoline\Xpsr\Http\Exception\NotFoundHttpException::class, $missing->attribute('error.type'));

        self::assertSame(404, $this->project->request('GET', '/payments/9')->getStatusCode());
        $notFound = $this->project->spans()->assertSpan('GET /payments/{id}');
        self::assertFalse($notFound->isError());
        self::assertNull($notFound->exception());
    }

    public function testSamplingAtZeroKeepsNoSpansAndTheInstrumentationCanBeTurnedOff(): void
    {
        $this->project->remove();
        $this->project = new Project(['sample' => 0]);
        $this->project->boot();
        self::assertSame(200, $this->project->request('GET', '/hello')->getStatusCode());
        $this->project->spans()->assertNothingTraced();
        $this->project->remove();

        $this->project = new Project(['trace' => ['requests' => false, 'queries' => false]]);
        $this->project->boot();
        self::assertSame(200, $this->project->request('GET', '/payments/1')->getStatusCode());
        $spans = $this->project->spans();
        $spans->assertNoSpan('GET /payments/{id}');
        $spans->assertNoSpan('SELECT telemetry_payments');
        $render = $spans->assertSpan('payment.render');
        self::assertTrue($render->isRoot(), 'Application spans still work, as roots.');
    }
}
