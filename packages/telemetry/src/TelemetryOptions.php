<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry;

use InvalidArgumentException;

/**
 * The "telemetry" configuration (config/telemetry.php), some settings falling back to the
 * OpenTelemetry environment variables:
 *
 *   enabled        off by default, so installing the package changes nothing   OTEL_ENABLED
 *   service_name   the service.name of every trace (default app.name)          OTEL_SERVICE_NAME
 *   service_version, environment (deployment.environment.name, default app.env)
 *   exporter       otlp (default), console, memory, or none                    OTEL_TRACES_EXPORTER
 *   endpoint       the collector's OTLP/HTTP URL; /v1/traces is appended to a   OTEL_EXPORTER_OTLP_ENDPOINT
 *                  base URL (default http://localhost:4318)
 *   headers        headers for the collector, such as an API key               OTEL_EXPORTER_OTLP_HEADERS
 *   protocol       http/protobuf (default) or http/json                        OTEL_EXPORTER_OTLP_PROTOCOL
 *   timeout        seconds to wait for the collector (default 10)
 *   client         a client of config/http_client.php for the export, or null for the default
 *   sample         the share of new traces kept, 0 to 1 (default 1)             OTEL_TRACES_SAMPLER_ARG
 *   batch          export in batches at shutdown (default true), or each span as it ends
 *   attributes     more resource attributes, such as ['service.namespace' => 'shop']
 *   trace          which instrumentations run: requests, queries, http_client, jobs, commands,
 *                  tasks, each true by default
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class TelemetryOptions
{
    public const EXPORTERS = ['otlp', 'console', 'memory', 'none'];
    public const PROTOCOLS = ['http/protobuf', 'http/json'];
    public const INSTRUMENTATIONS = ['requests', 'queries', 'http_client', 'jobs', 'commands', 'tasks'];

    /**
     * @param array<string, string> $headers
     * @param array<string, scalar> $attributes
     * @param array<string, bool> $trace
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $serviceName,
        public readonly ?string $serviceVersion,
        public readonly ?string $environment,
        public readonly string $exporter,
        public readonly string $endpoint,
        public readonly array $headers,
        public readonly string $protocol,
        public readonly float $timeout,
        public readonly ?string $client,
        public readonly float $sample,
        public readonly bool $batch,
        public readonly array $attributes,
        public readonly array $trace,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, mixed> $environment the OTEL_* environment variables
     */
    public static function from(array $config, array $environment, string $appName, string $appEnv): self
    {
        $known = ['enabled', 'service_name', 'service_version', 'environment', 'exporter', 'endpoint', 'headers', 'protocol', 'timeout', 'client', 'sample', 'batch', 'attributes', 'trace'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option telemetry.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $enabled = self::bool($config['enabled'] ?? $environment['OTEL_ENABLED'] ?? false, 'telemetry.enabled (or OTEL_ENABLED)');
        $serviceName = self::text($config['service_name'] ?? $environment['OTEL_SERVICE_NAME'] ?? null, 'telemetry.service_name') ?? ($appName !== '' ? $appName : 'app');
        $serviceVersion = self::text($config['service_version'] ?? null, 'telemetry.service_version');
        $deployment = self::text($config['environment'] ?? null, 'telemetry.environment') ?? ($appEnv !== '' ? $appEnv : null);
        $exporter = strtolower(self::text($config['exporter'] ?? $environment['OTEL_TRACES_EXPORTER'] ?? null, 'telemetry.exporter') ?? 'otlp');
        if (!in_array($exporter, self::EXPORTERS, true)) {
            throw new InvalidArgumentException(sprintf('telemetry.exporter (or OTEL_TRACES_EXPORTER) must be %s, not "%s".', implode(', ', self::EXPORTERS), $exporter));
        }
        $endpoint = self::text($config['endpoint'] ?? $environment['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? null, 'telemetry.endpoint') ?? 'http://localhost:4318';
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException("telemetry.endpoint (or OTEL_EXPORTER_OTLP_ENDPOINT) must be an http or https URL, not \"{$endpoint}\".");
        }
        $headers = self::headers($config['headers'] ?? $environment['OTEL_EXPORTER_OTLP_HEADERS'] ?? []);
        $protocol = strtolower(self::text($config['protocol'] ?? $environment['OTEL_EXPORTER_OTLP_PROTOCOL'] ?? null, 'telemetry.protocol') ?? 'http/protobuf');
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw new InvalidArgumentException(sprintf('telemetry.protocol (or OTEL_EXPORTER_OTLP_PROTOCOL) must be %s, not "%s".', implode(' or ', self::PROTOCOLS), $protocol));
        }
        $timeout = $config['timeout'] ?? 10;
        if (!is_int($timeout) && !is_float($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('telemetry.timeout must be a number of seconds above zero.');
        }
        $client = self::text($config['client'] ?? null, 'telemetry.client');
        $sample = $config['sample'] ?? $environment['OTEL_TRACES_SAMPLER_ARG'] ?? 1;
        if (is_string($sample) && is_numeric($sample)) {
            $sample = (float) $sample;
        }
        if (!is_int($sample) && !is_float($sample) || $sample < 0 || $sample > 1) {
            throw new InvalidArgumentException('telemetry.sample (or OTEL_TRACES_SAMPLER_ARG) must be a number from 0 to 1: the share of new traces kept.');
        }
        $batch = self::bool($config['batch'] ?? true, 'telemetry.batch');
        $attributes = $config['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw new InvalidArgumentException('telemetry.attributes must map attribute names to values.');
        }
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || $name === '' || !is_scalar($value)) {
                throw new InvalidArgumentException('telemetry.attributes must map attribute names to scalar values.');
            }
        }
        $trace = $config['trace'] ?? [];
        if (!is_array($trace)) {
            throw new InvalidArgumentException(sprintf('telemetry.trace must map instrumentations to true or false: %s.', implode(', ', self::INSTRUMENTATIONS)));
        }
        foreach (array_keys($trace) as $name) {
            if (!in_array($name, self::INSTRUMENTATIONS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option telemetry.trace.%s. Known instrumentations: %s.', $name, implode(', ', self::INSTRUMENTATIONS)));
            }
        }
        $instrumentations = [];
        foreach (self::INSTRUMENTATIONS as $name) {
            $instrumentations[$name] = self::bool($trace[$name] ?? true, "telemetry.trace.{$name}");
        }

        return new self($enabled, $serviceName, $serviceVersion, $deployment, $exporter, $endpoint, $headers, $protocol, (float) $timeout, $client, (float) $sample, $batch, $attributes, $instrumentations);
    }

    /** Whether an instrumentation runs: telemetry is enabled and the instrumentation is on. */
    public function traces(string $instrumentation): bool
    {
        return $this->enabled && ($this->trace[$instrumentation] ?? false);
    }

    private static function bool(mixed $value, string $setting): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === 0 || $value === 1) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException("{$setting} must be true or false.");
    }

    private static function text(mixed $value, string $setting): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$setting} must be a string.");
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function headers(mixed $value): array
    {
        if (is_string($value)) {
            // OTEL_EXPORTER_OTLP_HEADERS: key=value pairs separated by commas.
            $pairs = [];
            foreach (array_filter(array_map('trim', explode(',', $value))) as $pair) {
                if (!str_contains($pair, '=')) {
                    throw new InvalidArgumentException('OTEL_EXPORTER_OTLP_HEADERS must be key=value pairs separated by commas.');
                }
                [$name, $header] = explode('=', $pair, 2);
                $pairs[trim($name)] = trim(urldecode($header));
            }
            $value = $pairs;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('telemetry.headers must map header names to values.');
        }
        $headers = [];
        foreach ($value as $name => $header) {
            if (!is_string($name) || $name === '' || !is_scalar($header)) {
                throw new InvalidArgumentException('telemetry.headers must map header names to values.');
            }
            $headers[$name] = (string) $header;
        }

        return $headers;
    }
}
