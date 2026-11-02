<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests;

use Crystoline\Xpsr\HttpClient\HttpClientOptions;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\Telemetry\Http\TracingClient;
use Crystoline\Xpsr\Telemetry\Span;
use Crystoline\Xpsr\Telemetry\Telemetry;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Project;
use Crystoline\Xpsr\Telemetry\Testing\FinishedSpan;
use Crystoline\Xpsr\Telemetry\Tracer;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class ClientAndExportTest extends TestCase
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

    public function testOutgoingRequestsAreClientSpansCarryingTheTraceContext(): void
    {
        $this->project->client->queue(new Response(201), new Response(503), static function (RequestInterface $request): never {
            throw new class ('Connection refused.', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        });
        $app = $this->project->application;
        $client = $app->get(ClientInterface::class);
        self::assertInstanceOf(TracingClient::class, $client);
        self::assertInstanceOf(TracingClient::class, $app->get(HttpClientOptions::serviceId('payments')), 'Named clients are wrapped too.');
        self::assertSame($app->get(ClientInterface::class), $client, 'Still shared.');

        self::assertSame(201, $this->project->request('GET', '/call')->getStatusCode());
        $request = $this->project->spans()->assertSpan('GET /call');
        $call = $this->project->spans()->assertSpan('GET', static fn(FinishedSpan $span): bool => $span->kind() === 'client');
        self::assertSame($request->spanId(), $call->parentSpanId());
        self::assertSame('http://api.example/status?key=1', $call->attribute('url.full'));
        self::assertSame('api.example', $call->attribute('server.address'));
        self::assertSame(201, $call->attribute('http.response.status_code'));
        self::assertFalse($call->isError());
        $sent = $this->project->client->lastRequest();
        self::assertSame("00-{$call->traceId()}-{$call->spanId()}-01", $sent->getHeaderLine('traceparent'), 'The callee continues the trace from the client span.');
        $this->project->spans()->clear();

        $tracer = $app->get(Tracer::class);
        self::assertSame(503, $client->sendRequest(new Request('POST', 'http://user:secret@api.example:8443/charge'))->getStatusCode());
        $failed = $this->project->spans()->assertSpan('POST');
        self::assertTrue($failed->isError(), 'A client span fails on 4xx and 5xx.');
        self::assertSame('503', $failed->attribute('error.type'));
        self::assertSame('http://api.example:8443/charge', $failed->attribute('url.full'), 'Without the credentials.');
        self::assertSame(8443, $failed->attribute('server.port'));
        self::assertTrue($failed->isRoot());

        try {
            $tracer->span('outer', static fn(Span $span): mixed => $client->sendRequest(new Request('GET', 'http://api.example/down')));
            self::fail('Not thrown.');
        } catch (NetworkExceptionInterface $exception) {
            self::assertSame('Connection refused.', $exception->getMessage());
        }
        $down = $this->project->spans()->assertSpan('GET', static fn(FinishedSpan $span): bool => $span->attribute('url.full') === 'http://api.example/down');
        self::assertTrue($down->isError());
        self::assertNotNull($down->exception());
        self::assertSame($this->project->spans()->assertSpan('outer')->spanId(), $down->parentSpanId());
    }

    public function testSpansAreExportedOverOtlpThroughTheUntracedClient(): void
    {
        foreach (['http/protobuf' => 'application/x-protobuf', 'http/json' => 'application/json'] as $protocol => $contentType) {
            $this->project->remove();
            $this->project = new Project(['exporter' => 'otlp', 'endpoint' => 'http://collector:4318', 'protocol' => $protocol, 'headers' => ['x-api-key' => 'k1'], 'batch' => false, 'client' => 'payments', 'trace' => ['queries' => false]]);
            $this->project->boot();
            $this->project->payments->queue(new Response(200, ['Content-Type' => $contentType], $protocol === 'http/json' ? '{}' : ''));
            $this->project->application->get(Tracer::class)->span('exported', static fn(): null => null);
            $this->project->application->get(Telemetry::class)->flush();

            $sent = $this->project->payments->lastRequest();
            self::assertSame('POST', $sent->getMethod());
            self::assertSame('http://collector:4318/v1/traces', (string) $sent->getUri(), '/v1/traces is appended to a base URL.');
            self::assertSame($contentType, $sent->getHeaderLine('Content-Type'));
            self::assertSame('k1', $sent->getHeaderLine('x-api-key'));
            self::assertSame('', $sent->getHeaderLine('traceparent'), 'The export itself is not traced.');
            $body = (string) $sent->getBody();
            self::assertStringContainsString('exported', $body);
            self::assertStringContainsString('Shop', $body, 'The service name is in the resource.');
            if ($protocol === 'http/json') {
                self::assertStringContainsString('"deployment.environment.name"', $body);
            }
            self::assertSame([], $this->project->client->requests(), 'The default client was not used: telemetry.client names the payments client.');
            $this->project->application->get(Telemetry::class)->shutdown();
        }
    }

    public function testTheOtlpExporterNeedsAnHttpClient(): void
    {
        $telemetry = new Telemetry(\Crystoline\Xpsr\Telemetry\TelemetryOptions::from(['enabled' => true], [], 'Shop', 'local'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The otlp exporter needs crystoline/xpsr-http-client');
        $telemetry->tracer();
    }

    public function testWithTelemetryOffNothingIsWrappedOrListened(): void
    {
        $this->project->remove();
        $this->project = new Project(['enabled' => false]);
        $this->project->boot();
        $app = $this->project->application;
        self::assertInstanceOf(FakeClient::class, $app->get(ClientInterface::class));
        $this->project->client->queue(new Response(200));
        self::assertSame(200, $this->project->request('GET', '/call')->getStatusCode());
        self::assertSame('', $this->project->client->lastRequest()->getHeaderLine('traceparent'));
        self::assertSame(200, $this->project->request('GET', '/payments/1')->getStatusCode());
        self::assertArrayNotHasKey('trace_id', $this->project->logLines()[0]['extra'] ?? []);
        $tracer = $app->get(Tracer::class);
        self::assertSame('ran', $tracer->span('anything', static fn(): string => 'ran'));
        self::assertNull($tracer->traceId());
        $this->expectException(LogicException::class);
        $this->project->spans()->spans();
    }
}
