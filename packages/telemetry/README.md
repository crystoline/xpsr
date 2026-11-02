# crystoline/xpsr-telemetry

Distributed tracing for XPSR applications, on the [OpenTelemetry PHP SDK](https://opentelemetry.io/docs/languages/php/). A request through the application shows up in Jaeger, Grafana Tempo, Honeycomb, Datadog, or any other OTLP collector as one trace: the request, its database queries, the HTTP calls it made, the jobs it queued (even when a worker runs them later), with the console commands and scheduled tasks of the application as traces of their own. Log lines carry the trace and span IDs, so a log line, a trace, and a support ticket meet.

```sh
composer require crystoline/xpsr-telemetry
```

Composer asks whether to allow the `tbachert/spi` plugin the SDK ships with. Answer no: XPSR builds the SDK's components itself and needs none of the plugin's autoloading. To stop the question, add `"tbachert/spi": false` under `config.allow-plugins` in `composer.json`.

Add the provider to `app.registry` in `config/app.php`, after the providers of the packages it traces (database, HTTP client, queue, scheduler, Monolog), and the middleware first in `app.middleware`, so every other middleware is inside the request's span:

```php
'registry' => [
    // ...
    Crystoline\Xpsr\Telemetry\TelemetryServiceRegistry::class,
],
'middleware' => [
    Crystoline\Xpsr\Telemetry\Http\TraceRequestsMiddleware::class,
    // ...
],
```

Installing the package changes nothing until telemetry is turned on. Then the only setting most collectors need is where they listen:

```sh
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
```

## Configuration

Set environment variables, or override them in `config/telemetry.php`:

| Setting | Environment | Default | Values |
|---|---|---|---|
| `enabled` | `OTEL_ENABLED` | `false` | `true` turns tracing on. |
| `service_name` | `OTEL_SERVICE_NAME` | `app.name` | The `service.name` every trace carries. |
| `service_version` | | none | The `service.version` resource attribute, such as the release. |
| `environment` | | `app.env` | The `deployment.environment.name` resource attribute. |
| `exporter` | `OTEL_TRACES_EXPORTER` | `otlp` | `otlp`: OTLP over HTTP to the collector. `console`: JSON on standard output, for development. `memory`: kept for tests. `none`: dropped. |
| `endpoint` | `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | The collector's OTLP/HTTP URL. `/v1/traces` is appended to a base URL. |
| `headers` | `OTEL_EXPORTER_OTLP_HEADERS` | none | Headers for the collector, such as an API key: `['x-honeycomb-team' => '...']`, or `x-honeycomb-team=...` in the variable. |
| `protocol` | `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | Or `http/json`. |
| `timeout` | | `10` | Seconds to wait for the collector. |
| `client` | | the default client | A client of `config/http_client.php` for the export, for a proxy or certificates. |
| `sample` | `OTEL_TRACES_SAMPLER_ARG` | `1` | The share of new traces kept, 0 to 1. A request that continues an incoming trace follows that trace's decision. |
| `batch` | | `true` | Export spans in batches when the request or command ends, so a request never waits on the collector. `false` exports each span as it ends, for development. |
| `attributes` | | none | More resource attributes: `['service.namespace' => 'shop']`. |
| `trace` | | all on | Which instrumentations run: `['queries' => false]` keeps database queries out. The names are `requests`, `queries`, `http_client`, `jobs`, `commands`, and `tasks`. |

```php
// config/telemetry.php
return [
    'enabled' => env('APP_ENV') === 'production',
    'service_name' => 'shop-api',
    'service_version' => env('APP_RELEASE'),
    'endpoint' => 'https://api.honeycomb.io',
    'headers' => ['x-honeycomb-team' => env('HONEYCOMB_KEY')],
    'sample' => 0.1,
];
```

Invalid values fail when telemetry is first used, with the setting's name. The configuration wins over the environment variables.

## What is traced

Each span carries the attributes of the [OpenTelemetry semantic conventions](https://opentelemetry.io/docs/specs/semconv/), named in `Crystoline\Xpsr\Telemetry\Attributes`, plus a few of XPSR's own under `xpsr.`.

| Span | Kind | Name | Attributes |
|---|---|---|---|
| A request | server | `GET /payments/{id}`: the method and the route's path, or the method alone when no route matched | `http.request.method`, `http.route`, `http.response.status_code`, `url.path`, `url.query`, `url.scheme`, `server.address`, `server.port`, `client.address` (respecting `app.trusted_proxies`), `user_agent.original`, `network.protocol.version`, `xpsr.route.name`, and `xpsr.request_id` with the Monolog package. An incoming `traceparent` header makes the span part of the caller's trace. |
| A database query | client | `SELECT payments`: the operation and the first table | `db.system.name`, `db.operation.name`, `db.collection.name`, `db.query.text` (the SQL with its placeholders; parameter values are never recorded), `xpsr.db.connection`. From the `QueryExecuted` event of `crystoline/xpsr-database`, for DBAL and Eloquent alike. |
| An outgoing HTTP request | client | `POST`: the method | `http.request.method`, `url.full` (without credentials), `server.address`, `server.port`, `http.response.status_code`. The request carries `traceparent`, so a service on XPSR, or on any OpenTelemetry SDK, continues the trace. Through the default and the named clients of `crystoline/xpsr-http-client`; Symfony's `HttpClientInterface` is not traced. |
| Queuing a job | producer | `send default`: the transport | `messaging.system`, `messaging.operation.type`, `messaging.destination.name`, `xpsr.job`. The job is queued with the trace context, in a `TraceContextStamp`. |
| Running a job | consumer | `job App\Jobs\SendReceipt` | The same, in the trace that queued the job, whether a worker runs it or `sync://` runs it at once. A job queued without a context starts a trace of its own rather than joining the worker's. |
| A console command | internal | `command migrate` | `xpsr.command`, `xpsr.command.exit_code`. Not for `queue:work`, `schedule:work`, and `serve`, which run as long as the process: their jobs and tasks have their own traces. |
| A scheduled task | internal | `task reports:daily`: the task's description | `xpsr.task`, `xpsr.task.kind`, `xpsr.task.status`. A command task's command gets its span inside; a job task's job gets its own trace when a worker runs it. |

A span fails, with the exception recorded as an event, when a request answers 5xx or an exception reaches the kernel, when an outgoing request answers 4xx or 5xx or cannot be sent, when a job throws, when a command throws or exits with a code other than 0, and when a task fails. A 4xx answer of the application is not a failure, and the span of a request that matched no route stays named by its method.

## Your own spans

`Crystoline\Xpsr\Telemetry\Tracer` wraps the SDK's tracer, so application code does not depend on OpenTelemetry's API. A span is a child of the current one (the request, job, or command), is current while the closure runs, records an exception the closure throws and rethrows it, and ends when the closure returns:

```php
use Crystoline\Xpsr\Telemetry\Span;
use Crystoline\Xpsr\Telemetry\Tracer;

final class ChargeController
{
    public function __construct(private readonly Tracer $tracer, private readonly Gateway $gateway) {}

    public function capture(ServerRequestInterface $request): ResponseInterface
    {
        $charge = $this->tracer->span('charge.capture', function (Span $span) use ($request): Charge {
            $span->setAttribute('charge.id', $request->getAttribute('id'));
            $charge = $this->gateway->capture($request->getAttribute('id'));
            $span->addEvent('gateway.answered', ['reference' => $charge->reference]);

            return $charge;
        });
        // ...
    }
}
```

`start($name, $attributes)` gives a span to `end()` by hand; `current()` is the span in progress, with `traceId()` and `spanId()` to show a user or write somewhere; `inject($headers)` writes the trace context into an array of headers for a message that continues the trace elsewhere, and `extract($headers)` reads one back. Attributes are strings, numbers, booleans, or lists of one of them; enums and `Stringable` objects become strings. With telemetry off, closures still run and spans do nothing.

The SDK's own API works too: the tracer provider is registered with `OpenTelemetry\API\Globals`, so a library instrumented with OpenTelemetry joins the same traces.

## Logs

With [`crystoline/xpsr-log-monolog`](../log-monolog/README.md), every log line written inside a span carries `trace_id` and `span_id` in its extra data, next to `request_id`. Search the collector for the trace ID of a log line, or the logs for the trace ID of a slow span. List the telemetry provider after the Monolog one.

## Exporting

Spans are kept until the request or command ends, then sent in one batch from a shutdown function, so a request never waits on the collector; a worker sends a batch every few seconds or every 512 spans. `Telemetry::flush()` sends what is finished now. The export goes through the HTTP client package's client (or the one `telemetry.client` names), without being traced itself.

The `console` exporter prints each span as JSON on standard output: `OTEL_TRACES_EXPORTER=console php xpsr migrate` shows what a command would send. The SDK's other `OTEL_*` variables are not read: XPSR configures the SDK from the settings above.

## Testing

Turn telemetry on with the memory exporter in the test environment, in `phpunit.xml`:

```xml
<env name="OTEL_ENABLED" value="true"/>
<env name="OTEL_TRACES_EXPORTER" value="memory"/>
```

`Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry` then has the spans the application finished:

```php
use Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry;
use Crystoline\Xpsr\Telemetry\Testing\FinishedSpan;

$this->postJson('/payments', ['amount' => 500]);

$spans = $this->application()->get(FakeTelemetry::class);
$request = $spans->assertSpan('POST /payments', static fn(FinishedSpan $span): bool => $span->attribute('http.response.status_code') === 201);
$spans->assertSpan('job App\Jobs\SendReceipt', static fn(FinishedSpan $span): bool => $span->traceId() === $request->traceId());
$spans->assertSpan('charge.capture', null, 1);                   // exactly once
$spans->assertNoSpan('SELECT payments', static fn(FinishedSpan $span): bool => $span->isError());
$spans->assertNothingTraced();
```

A `FinishedSpan` has `name()`, `kind()`, `attribute($name)`, `attributes()`, `isError()`, `error()`, `exception()`, `events()`, `traceId()`, `spanId()`, `parentSpanId()`, `isRoot()`, and `durationNanos()`. `spans()` lists them in the order they ended, `find($name)` those with a name, and `clear()` forgets them. A failed assertion names the spans that finished.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Telemetry\Tracer` | `span()`, `start()`, `current()`, `traceId()`, `spanId()`, `inject()`, `extract()`. Shared. |
| `Crystoline\Xpsr\Telemetry\Telemetry` | The tracer provider: `enabled()`, `options()`, `flush()`, `shutdown()`. Shared. |
| `Crystoline\Xpsr\Telemetry\Http\TraceRequestsMiddleware` | The request span; first in `app.middleware`. |
| `Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry` | The finished spans, with the memory exporter. Shared. |

Not provided: metrics and OpenTelemetry logs (traces first; metrics can follow once there is a use for them), the gRPC transport (the OTLP/HTTP one serves every collector), and spans for views or cache calls.

Requires PHP 8.4+, `open-telemetry/sdk` 1.15+, and `open-telemetry/exporter-otlp` 1.4+. The database, HTTP client, queue, scheduler, and Monolog packages are optional and detected.
