<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The "health" configuration (config/health.php):
 *
 *   checks          your own checks, as service IDs or class names (default none); see CheckInterface
 *   skip            names of checks not to run, such as ['disk'] (default none)
 *   secret          a token that shows a report's details to anyone who sends it   HEALTH_SECRET
 *   cache           seconds a report is kept before the checks run again (default 5; 0 runs them every time)
 *   failed_jobs     the number of failed jobs above which the queue check warns (default 0)
 *   worker_stale    how long after its last heartbeat a worker counts as gone (default "5 minutes")
 *   schedule_stale  the same for schedule:run (default "3 minutes")
 *   connections     the database connections to check (default all of database.connections)
 *   disk            ['path' => the project root, 'warning' => 10, 'failing' => 5]: percent of free space
 *   http            ['gateway' => 'https://api.gateway.example/health']: dependencies to fetch
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class HealthOptions
{
    public const BUILT_IN = ['database', 'cache', 'queue', 'workers', 'scheduler', 'locks', 'disk', 'http'];

    /**
     * @param list<string> $checks
     * @param list<string> $skip
     * @param list<string>|null $connections
     * @param array<string, string> $http
     */
    private function __construct(
        public readonly array $checks,
        public readonly array $skip,
        public readonly ?string $secret,
        public readonly int $cache,
        public readonly int $failedJobs,
        public readonly int $workerStale,
        public readonly int $scheduleStale,
        public readonly ?array $connections,
        public readonly ?string $diskPath,
        public readonly float $diskWarning,
        public readonly float $diskFailing,
        public readonly array $http,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function from(array $config, mixed $secretEnvironment = null): self
    {
        $known = ['checks', 'skip', 'secret', 'cache', 'failed_jobs', 'worker_stale', 'schedule_stale', 'connections', 'disk', 'http'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option health.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $checks = self::strings($config['checks'] ?? [], 'health.checks must be a list of check service IDs or class names.');
        $skip = self::strings($config['skip'] ?? [], 'health.skip must be a list of check names.');
        $secret = $config['secret'] ?? $secretEnvironment ?? null;
        if ($secret === '' || $secret === false) {
            $secret = null;
        }
        if ($secret !== null && !is_string($secret)) {
            throw new InvalidArgumentException('health.secret (or HEALTH_SECRET) must be a string.');
        }
        $cache = $config['cache'] ?? 5;
        if (!is_int($cache) || $cache < 0) {
            throw new InvalidArgumentException('health.cache must be a whole number of seconds, 0 or more.');
        }
        $failedJobs = $config['failed_jobs'] ?? 0;
        if (!is_int($failedJobs) || $failedJobs < 0) {
            throw new InvalidArgumentException('health.failed_jobs must be a whole number of jobs, 0 or more.');
        }
        $connections = $config['connections'] ?? null;
        if ($connections !== null) {
            $connections = self::strings($connections, 'health.connections must be a list of connection names.');
        }
        $disk = $config['disk'] ?? [];
        if (!is_array($disk)) {
            throw new InvalidArgumentException('health.disk must be an array with path, warning, and failing.');
        }
        foreach (array_keys($disk) as $key) {
            if (!in_array($key, ['path', 'warning', 'failing'], true)) {
                throw new InvalidArgumentException("Unknown option health.disk.{$key}. Known options: path, warning, failing.");
            }
        }
        $diskPath = $disk['path'] ?? null;
        if ($diskPath !== null && (!is_string($diskPath) || $diskPath === '')) {
            throw new InvalidArgumentException('health.disk.path must be a directory.');
        }
        $diskWarning = self::percent($disk['warning'] ?? 10, 'health.disk.warning');
        $diskFailing = self::percent($disk['failing'] ?? 5, 'health.disk.failing');
        if ($diskFailing > $diskWarning) {
            throw new InvalidArgumentException('health.disk.failing must not be above health.disk.warning.');
        }
        $http = $config['http'] ?? [];
        if (!is_array($http)) {
            throw new InvalidArgumentException('health.http must map names to URLs.');
        }
        foreach ($http as $name => $url) {
            if (!is_string($name) || $name === '' || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new InvalidArgumentException('health.http must map names to http or https URLs.');
            }
        }

        return new self(
            $checks,
            $skip,
            $secret,
            $cache,
            $failedJobs,
            self::seconds($config['worker_stale'] ?? '5 minutes', 'health.worker_stale'),
            self::seconds($config['schedule_stale'] ?? '3 minutes', 'health.schedule_stale'),
            $connections,
            $diskPath,
            $diskWarning,
            $diskFailing,
            $http,
        );
    }

    /** Whether a check of that name runs. */
    public function runs(string $name): bool
    {
        return !in_array($name, $this->skip, true);
    }

    /** Seconds from a number of seconds or a period such as "5 minutes". */
    public static function seconds(mixed $value, string $setting): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A\d+\s*(second|minute|hour|day)s?\z/i', trim($value)) === 1) {
            $seconds = (new DateTimeImmutable('@0'))->modify('+' . trim($value))->getTimestamp();
            if ($seconds > 0) {
                return $seconds;
            }
        }

        throw new InvalidArgumentException("{$setting} must be a number of seconds above zero, or a period such as \"5 minutes\".");
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value) || $value !== array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== '')) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private static function percent(mixed $value, string $setting): float
    {
        if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 100) {
            throw new InvalidArgumentException("{$setting} must be a percentage of free space, 0 to 100.");
        }

        return (float) $value;
    }
}
