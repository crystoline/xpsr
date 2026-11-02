<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests;

use Crystoline\Xpsr\Telemetry\TelemetryOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelemetryOptionsTest extends TestCase
{
    public function testDefaultsAreOffAndOtlpToALocalCollector(): void
    {
        $options = TelemetryOptions::from([], [], 'Shop', 'production');
        self::assertFalse($options->enabled);
        self::assertSame('Shop', $options->serviceName);
        self::assertNull($options->serviceVersion);
        self::assertSame('production', $options->environment);
        self::assertSame('otlp', $options->exporter);
        self::assertSame('http://localhost:4318', $options->endpoint);
        self::assertSame([], $options->headers);
        self::assertSame('http/protobuf', $options->protocol);
        self::assertSame(10.0, $options->timeout);
        self::assertNull($options->client);
        self::assertSame(1.0, $options->sample);
        self::assertTrue($options->batch);
        self::assertSame([], $options->attributes);
        self::assertSame(array_fill_keys(TelemetryOptions::INSTRUMENTATIONS, true), $options->trace);
        self::assertFalse($options->traces('requests'), 'Nothing is traced while telemetry is off.');
        self::assertSame('app', TelemetryOptions::from([], [], '', '')->serviceName);
        self::assertNull(TelemetryOptions::from([], [], '', '')->environment);
    }

    public function testEnvironmentVariablesApplyWhenTheConfigurationIsSilent(): void
    {
        $environment = [
            'OTEL_ENABLED' => 'true',
            'OTEL_SERVICE_NAME' => 'shop-api',
            'OTEL_TRACES_EXPORTER' => 'console',
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otlp.example.com',
            'OTEL_EXPORTER_OTLP_HEADERS' => 'x-api-key=abc%20def, x-team=payments',
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/json',
            'OTEL_TRACES_SAMPLER_ARG' => '0.25',
        ];
        $options = TelemetryOptions::from([], $environment, 'Shop', 'production');
        self::assertTrue($options->enabled);
        self::assertSame('shop-api', $options->serviceName);
        self::assertSame('console', $options->exporter);
        self::assertSame('https://otlp.example.com', $options->endpoint);
        self::assertSame(['x-api-key' => 'abc def', 'x-team' => 'payments'], $options->headers);
        self::assertSame('http/json', $options->protocol);
        self::assertSame(0.25, $options->sample);
        self::assertTrue($options->traces('queries'));

        $configured = TelemetryOptions::from([
            'enabled' => false,
            'service_name' => 'shop',
            'service_version' => '2.1.0',
            'environment' => 'staging',
            'exporter' => 'memory',
            'endpoint' => 'http://collector:4318/v1/traces',
            'headers' => ['Authorization' => 'Bearer t'],
            'protocol' => 'http/protobuf',
            'timeout' => 2.5,
            'client' => 'collector',
            'sample' => 0,
            'batch' => false,
            'attributes' => ['service.namespace' => 'shop', 'host.rack' => 7],
            'trace' => ['queries' => false],
        ], $environment, 'Shop', 'production');
        self::assertFalse($configured->enabled, 'The configuration wins over the environment.');
        self::assertSame('shop', $configured->serviceName);
        self::assertSame('2.1.0', $configured->serviceVersion);
        self::assertSame('staging', $configured->environment);
        self::assertSame('memory', $configured->exporter);
        self::assertSame('http://collector:4318/v1/traces', $configured->endpoint);
        self::assertSame(['Authorization' => 'Bearer t'], $configured->headers);
        self::assertSame(2.5, $configured->timeout);
        self::assertSame('collector', $configured->client);
        self::assertSame(0.0, $configured->sample);
        self::assertFalse($configured->batch);
        self::assertSame(['service.namespace' => 'shop', 'host.rack' => 7], $configured->attributes);
        self::assertFalse($configured->trace['queries']);
        self::assertTrue($configured->trace['requests']);

        $enabled = TelemetryOptions::from(['enabled' => 'yes', 'trace' => ['jobs' => 'off']], [], 'Shop', 'local');
        self::assertTrue($enabled->traces('requests'));
        self::assertFalse($enabled->traces('jobs'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown option' => [['exporters' => 'otlp'], 'Unknown option telemetry.exporters. Known options: enabled, service_name'];
        yield 'enabled' => [['enabled' => 'maybe'], 'telemetry.enabled (or OTEL_ENABLED) must be true or false.'];
        yield 'exporter' => [['exporter' => 'jaeger'], 'telemetry.exporter (or OTEL_TRACES_EXPORTER) must be otlp, console, memory, none, not "jaeger".'];
        yield 'endpoint' => [['endpoint' => 'collector:4318'], 'telemetry.endpoint (or OTEL_EXPORTER_OTLP_ENDPOINT) must be an http or https URL, not "collector:4318".'];
        yield 'headers' => [['headers' => ['x-api-key']], 'telemetry.headers must map header names to values.'];
        yield 'protocol' => [['protocol' => 'grpc'], 'telemetry.protocol (or OTEL_EXPORTER_OTLP_PROTOCOL) must be http/protobuf or http/json, not "grpc".'];
        yield 'timeout' => [['timeout' => 0], 'telemetry.timeout must be a number of seconds above zero.'];
        yield 'sample' => [['sample' => 2], 'telemetry.sample (or OTEL_TRACES_SAMPLER_ARG) must be a number from 0 to 1'];
        yield 'attributes' => [['attributes' => ['team' => ['a']]], 'telemetry.attributes must map attribute names to scalar values.'];
        yield 'trace' => [['trace' => ['views' => true]], 'Unknown option telemetry.trace.views. Known instrumentations: requests, queries, http_client, jobs, commands, tasks.'];
        yield 'trace value' => [['trace' => ['jobs' => 'sometimes']], 'telemetry.trace.jobs must be true or false.'];
        yield 'service name' => [['service_name' => 7], 'telemetry.service_name must be a string.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidOptionsNameTheSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        TelemetryOptions::from($config, [], 'Shop', 'local');
    }

    public function testEnvironmentHeadersMustBePairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OTEL_EXPORTER_OTLP_HEADERS must be key=value pairs separated by commas.');
        TelemetryOptions::from([], ['OTEL_EXPORTER_OTLP_HEADERS' => 'x-api-key'], 'Shop', 'local');
    }
}
