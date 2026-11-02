<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes as SdkAttributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Resource\Detectors\Sdk;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The OpenTelemetry tracer provider of the application: built from TelemetryOptions with the
 * exporter, the sampler, and the service's resource attributes; a no-op when telemetry is off.
 * Spans are exported in batches at shutdown (telemetry.batch), or as each one ends.
 */
final class Telemetry
{
    public const INSTRUMENTATION = 'crystoline/xpsr-telemetry';

    private ?TracerProviderInterface $provider = null;
    private ?TracerInterface $tracer = null;
    private ?InMemoryExporter $memory = null;

    /**
     * @param (\Closure(): ClientInterface)|null $client the HTTP client for the OTLP export, untraced
     */
    public function __construct(
        private readonly TelemetryOptions $options,
        private readonly ?\Closure $client = null,
        private readonly ?RequestFactoryInterface $requests = null,
        private readonly ?StreamFactoryInterface $streams = null,
    ) {}

    public function enabled(): bool
    {
        return $this->options->enabled;
    }

    public function options(): TelemetryOptions
    {
        return $this->options;
    }

    public function tracer(): TracerInterface
    {
        if ($this->tracer !== null) {
            return $this->tracer;
        }
        if (!$this->options->enabled) {
            return $this->tracer = NoopTracer::getInstance();
        }

        return $this->tracer = $this->provider()->getTracer(self::INSTRUMENTATION);
    }

    public function propagator(): \OpenTelemetry\Context\Propagation\TextMapPropagatorInterface
    {
        return $this->options->enabled ? TraceContextPropagator::getInstance() : NoopTextMapPropagator::getInstance();
    }

    /** Sends the spans ended so far to the exporter now, instead of at shutdown. */
    public function flush(): void
    {
        $this->provider?->forceFlush();
    }

    /** Sends the remaining spans and stops exporting; called at shutdown. */
    public function shutdown(): void
    {
        $this->provider?->shutdown();
        $this->provider = null;
        $this->tracer = null;
    }

    /** The spans the "memory" exporter kept, for tests; empty with any other exporter. */
    public function memory(): ?InMemoryExporter
    {
        return $this->memory;
    }

    /** @internal */
    public function provider(): TracerProviderInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }
        $builder = TracerProvider::builder()->setSampler($this->sampler())->setResource($this->resource());
        if ($this->options->exporter !== 'none') {
            $exporter = $this->exporter();
            $builder->addSpanProcessor($this->options->batch ? new BatchSpanProcessor($exporter, Clock::getDefault()) : new SimpleSpanProcessor($exporter));
        }
        $this->provider = $builder->build();
        $provider = $this->provider;
        Globals::registerInitializer(fn(Configurator $configurator): Configurator => $configurator->withTracerProvider($provider)->withPropagator($this->propagator()));
        register_shutdown_function($this->shutdown(...));

        return $this->provider;
    }

    private function exporter(): SpanExporterInterface
    {
        return match ($this->options->exporter) {
            'otlp' => $this->otlp(),
            'console' => new ConsoleSpanExporter((new StreamTransportFactory())->create('php://stdout', 'application/json')),
            default => $this->memory = new InMemoryExporter(),
        };
    }

    private function otlp(): OtlpSpanExporter
    {
        if ($this->client === null) {
            throw new \LogicException('The otlp exporter needs crystoline/xpsr-http-client: run composer require crystoline/xpsr-http-client, and add Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry to app.registry before the telemetry registry.');
        }
        $transport = (new PsrTransportFactory(($this->client)(), $this->requests, $this->streams))->create(
            self::tracesEndpoint($this->options->endpoint),
            $this->options->protocol === 'http/json' ? ContentTypes::JSON : ContentTypes::PROTOBUF,
            $this->options->headers,
            timeout: $this->options->timeout,
        );

        return new OtlpSpanExporter($transport);
    }

    /** The OTLP/HTTP traces URL: a base URL such as http://collector:4318 gets /v1/traces appended. */
    public static function tracesEndpoint(string $endpoint): string
    {
        $path = (string) parse_url($endpoint, PHP_URL_PATH);
        if (str_ends_with(rtrim($path, '/'), '/v1/traces')) {
            return $endpoint;
        }

        return rtrim($endpoint, '/') . '/v1/traces';
    }

    private function sampler(): ParentBased
    {
        $root = match (true) {
            $this->options->sample >= 1 => new AlwaysOnSampler(),
            $this->options->sample <= 0 => new AlwaysOffSampler(),
            default => new TraceIdRatioBasedSampler($this->options->sample),
        };

        return new ParentBased($root);
    }

    private function resource(): ResourceInfo
    {
        $attributes = ['service.name' => $this->options->serviceName];
        if ($this->options->serviceVersion !== null) {
            $attributes['service.version'] = $this->options->serviceVersion;
        }
        if ($this->options->environment !== null) {
            $attributes['deployment.environment.name'] = $this->options->environment;
        }

        return ResourceInfoFactory::emptyResource()
            ->merge((new Sdk())->getResource())
            ->merge(ResourceInfo::create(SdkAttributes::create($attributes + $this->options->attributes)));
    }
}
