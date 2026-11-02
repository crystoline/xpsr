<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\Event\CommandFinished;
use Crystoline\Xpsr\Console\Event\CommandStarting;
use Crystoline\Xpsr\Database\Event\QueryExecuted;
use Crystoline\Xpsr\Events\ListenerProvider;
use Crystoline\Xpsr\HttpClient\HttpClientOptions;
use Crystoline\Xpsr\Log\Monolog\LogProcessors;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\Queue\BusMiddleware;
use Crystoline\Xpsr\Routing\Event\RouteMatched;
use Crystoline\Xpsr\Scheduler\Event\TaskFinished;
use Crystoline\Xpsr\Scheduler\Event\TaskStarting;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Telemetry\Console\TraceCommands;
use Crystoline\Xpsr\Telemetry\Database\TraceQueries;
use Crystoline\Xpsr\Telemetry\Http\RouteListener;
use Crystoline\Xpsr\Telemetry\Http\TraceRequestsMiddleware;
use Crystoline\Xpsr\Telemetry\Http\TracingClient;
use Crystoline\Xpsr\Telemetry\Log\TraceContextProcessor;
use Crystoline\Xpsr\Telemetry\Queue\TraceJobs;
use Crystoline\Xpsr\Telemetry\Scheduler\TraceTasks;
use Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers tracing, configured in config/telemetry.php and the OTEL_* environment variables
 * (see TelemetryOptions), off until telemetry.enabled or OTEL_ENABLED is true:
 *
 *   Crystoline\Xpsr\Telemetry\Tracer                       spans for application code. Shared.
 *   Crystoline\Xpsr\Telemetry\Telemetry                    the tracer provider; flush(). Shared.
 *   Crystoline\Xpsr\Telemetry\Http\TraceRequestsMiddleware for app.middleware, first.
 *   Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry        the finished spans, in tests.
 *
 * With telemetry on, it traces what telemetry.trace allows: requests (the middleware, and the
 * route's name from RouteMatched), queries (QueryExecuted of crystoline/xpsr-database),
 * http_client (the default and named clients of crystoline/xpsr-http-client, wrapped), jobs
 * (the bus of crystoline/xpsr-queue), commands (CommandStarting and CommandFinished), and
 * tasks (TaskStarting and TaskFinished of crystoline/xpsr-scheduler); and with
 * crystoline/xpsr-log-monolog, log lines get the trace and span IDs. List it after the
 * providers of those packages.
 */
final class TelemetryServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(TelemetryOptions::class, static function (ContainerInterface $container): TelemetryOptions {
            $config = $container->get(Configuration::class);
            $telemetry = $config->get('telemetry', []);
            if (!is_array($telemetry)) {
                throw new InvalidArgumentException('The telemetry configuration must be an array.');
            }
            $environment = [];
            foreach (['OTEL_ENABLED', 'OTEL_SERVICE_NAME', 'OTEL_TRACES_EXPORTER', 'OTEL_EXPORTER_OTLP_ENDPOINT', 'OTEL_EXPORTER_OTLP_HEADERS', 'OTEL_EXPORTER_OTLP_PROTOCOL', 'OTEL_TRACES_SAMPLER_ARG'] as $name) {
                $environment[$name] = env($name);
            }

            return TelemetryOptions::from($telemetry, $environment, (string) $config->get('app.name', 'app'), (string) $config->get('app.env', 'production'));
        }, shared: true);
        $this->container->factory(Telemetry::class, static function (ContainerInterface $container): Telemetry {
            $options = $container->get(TelemetryOptions::class);
            $clientId = $options->client === null ? ClientInterface::class : HttpClientOptions::serviceId($options->client);
            $client = null;
            if (self::installed(HttpClientOptions::class) && $container->has($clientId)) {
                $client = static function () use ($container, $clientId): ClientInterface {
                    $client = $container->get($clientId);

                    // The export must not be traced, or each export would make a span to export.
                    return $client instanceof TracingClient ? $client->inner() : $client;
                };
            }

            return new Telemetry(
                $options,
                $client,
                $container->has(RequestFactoryInterface::class) ? $container->get(RequestFactoryInterface::class) : null,
                $container->has(StreamFactoryInterface::class) ? $container->get(StreamFactoryInterface::class) : null,
            );
        }, shared: true);
        $this->container->factory(Tracer::class, static function (ContainerInterface $container): Tracer {
            $telemetry = $container->get(Telemetry::class);

            return new Tracer($telemetry->tracer(), $telemetry->propagator());
        }, shared: true);
        $this->container->factory(TraceRequestsMiddleware::class, static function (ContainerInterface $container): TraceRequestsMiddleware {
            $proxies = $container->get(Configuration::class)->get('app.trusted_proxies', []);
            $requestIds = self::installed(RequestId::class) && $container->has(RequestId::class) ? $container->get(RequestId::class) : null;

            return new TraceRequestsMiddleware(
                $container->get(Tracer::class),
                $container->get(TelemetryOptions::class)->traces('requests'),
                is_array($proxies) ? array_values(array_filter($proxies, 'is_string')) : [],
                $requestIds === null ? null : static fn(): ?string => $requestIds->get(),
            );
        });
        $this->container->factory(TraceQueries::class, static fn(ContainerInterface $container): TraceQueries => new TraceQueries(
            $container->get(Tracer::class),
            static function (string $connection) use ($container): ?string {
                $config = $container->get(Configuration::class)->get("database.connections.{$connection}", []);
                if (!is_array($config)) {
                    return null;
                }
                $driver = $config['driver'] ?? null;
                if (is_string($driver)) {
                    return $driver;
                }
                $url = $config['url'] ?? null;

                return is_string($url) ? (string) parse_url($url, PHP_URL_SCHEME) : null;
            },
        ), shared: true);
        $this->container->factory(RouteListener::class, static fn(ContainerInterface $container): RouteListener => new RouteListener($container->get(Tracer::class)), shared: true);
        $this->container->factory(TraceJobs::class, static fn(ContainerInterface $container): TraceJobs => new TraceJobs($container->get(Tracer::class)), shared: true);
        $this->container->factory(TraceCommands::class, static fn(ContainerInterface $container): TraceCommands => new TraceCommands($container->get(Tracer::class)), shared: true);
        $this->container->factory(TraceTasks::class, static fn(ContainerInterface $container): TraceTasks => new TraceTasks($container->get(Tracer::class)), shared: true);
        $this->container->factory(TraceContextProcessor::class, static fn(ContainerInterface $container): TraceContextProcessor => new TraceContextProcessor($container->get(Tracer::class)), shared: true);
        $this->container->factory(FakeTelemetry::class, static fn(ContainerInterface $container): FakeTelemetry => new FakeTelemetry($container->get(Telemetry::class)), shared: true);

        $options = $this->container->get(TelemetryOptions::class);
        if ($options->traces('http_client') && self::installed(HttpClientOptions::class)) {
            $wrap = static fn(mixed $client, ContainerInterface $container): mixed => $client instanceof ClientInterface && !$client instanceof TracingClient
                ? new TracingClient($client, static fn(): Tracer => $container->get(Tracer::class))
                : $client;
            if ($this->container->has(ClientInterface::class)) {
                $this->container->extend(ClientInterface::class, $wrap);
            }
            $clients = $this->container->get(Configuration::class)->get('http_client.clients', []);
            foreach (is_array($clients) ? array_keys($clients) : [] as $name) {
                if (is_string($name) && $this->container->has(HttpClientOptions::serviceId($name))) {
                    $this->container->extend(HttpClientOptions::serviceId($name), $wrap);
                }
            }
        }
    }

    public function boot(): void
    {
        $options = $this->container->get(TelemetryOptions::class);
        if (!$options->enabled) {
            return;
        }
        $listeners = $this->container->has(ListenerProvider::class) ? $this->container->get(ListenerProvider::class) : null;
        if ($listeners instanceof ListenerProvider) {
            if ($options->traces('requests')) {
                $listeners->listen(RouteMatched::class, RouteListener::class);
            }
            if ($options->traces('queries') && self::installed(QueryExecuted::class)) {
                $listeners->listen(QueryExecuted::class, TraceQueries::class);
            }
            if ($options->traces('commands')) {
                $listeners->listen(CommandStarting::class, [TraceCommands::class, 'starting']);
                $listeners->listen(CommandFinished::class, [TraceCommands::class, 'finished']);
            }
            if ($options->traces('tasks') && self::installed(TaskStarting::class)) {
                $listeners->listen(TaskStarting::class, [TraceTasks::class, 'starting']);
                $listeners->listen(TaskFinished::class, [TraceTasks::class, 'finished']);
            }
        }
        if ($options->traces('jobs') && self::installed(BusMiddleware::class) && $this->container->has(BusMiddleware::class)) {
            $this->container->get(BusMiddleware::class)->add(TraceJobs::class);
        }
        if (self::installed(LogProcessors::class) && $this->container->has(LogProcessors::class)) {
            $this->container->get(LogProcessors::class)->add(TraceContextProcessor::class);
        }
    }

    /** Whether an optional package's class exists; a function, so analysis does not fold it. */
    private static function installed(string $class): bool
    {
        return class_exists($class);
    }
}
